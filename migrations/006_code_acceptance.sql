-- Приёмка кодов от поставщиков.
--
-- До этой миграции ответ поставщика принимался на веру: что он прислал,
-- то и уходило покупателю. Поставщику доверять нельзя — он может выдать один
-- код дважды, прислать чужой код или ответить ошибкой, выдав код на самом деле.
--
-- Защита строится не на проверках в коде, а на ограничениях таблицы приёмки:
-- проверка перед вставкой между чтением и записью пропускает конкурента,
-- ограничение не пропускает никогда.

CREATE TABLE issued_codes (
    -- Код — первичный ключ, и уникален он глобально, а не в пределах
    -- поставщика. Для цифрового товара строка кода и есть сам товар: если
    -- два поставщика прислали одну строку, товар всё равно один, и уйти
    -- в два заказа он не может.
    code text PRIMARY KEY,

    provider    text        NOT NULL,
    request_id  text        NOT NULL,
    order_id    text        NOT NULL,
    position    integer     NOT NULL,
    accepted_at timestamptz NOT NULL DEFAULT now(),

    FOREIGN KEY (order_id, position) REFERENCES order_items (order_id, position)
);

-- Один запрос — один код. Если поставщик по тому же request_id прислал другую
-- строку, вторая не запишется: принятым остаётся первый принятый код.
CREATE UNIQUE INDEX issued_codes_request_idx ON issued_codes (provider, request_id);

CREATE INDEX issued_codes_item_idx ON issued_codes (order_id, position);

-- Существующие выдачи переносятся в приёмку: без этого сверка сочла бы
-- их коды непринятыми.
INSERT INTO issued_codes (code, provider, request_id, order_id, position, accepted_at)
SELECT d.code, d.provider, d.request_id, d.order_id, d.position, d.delivered_at
  FROM deliveries d
 WHERE d.status = 'delivered' AND d.code IS NOT NULL
ON CONFLICT DO NOTHING;

-- Копия принятого кода в выдаче не должна расходиться с приёмкой.
-- Уникальность здесь избыточна по отношению к issued_codes, но стоит дёшево
-- и закрывает путь, которым код мог бы попасть в две позиции в обход приёмки.
CREATE UNIQUE INDEX deliveries_code_idx ON deliveries (code) WHERE code IS NOT NULL;

-- Поколение запроса.
--
-- request_id детерминирован, поэтому повтор получает от поставщика тот же код.
-- Обычно это защита, но если код отклонён приёмкой как чужой, тот же request_id
-- будет возвращать его вечно. Поколение — единственный способ запросить другой
-- код у того же поставщика; растёт только при отклонении, а не при повторе.
ALTER TABLE deliveries ADD COLUMN generation integer NOT NULL DEFAULT 1
    CHECK (generation > 0);

ALTER TABLE delivery_attempts ADD COLUMN generation integer NOT NULL DEFAULT 1;

-- Журнал расхождений с поставщиком.
--
-- Строка появляется, когда ответ поставщика разошёлся с действительностью,
-- и закрывается, когда расхождение разобрано. Нужен для отчёта: разбор
-- идёт автоматически, но факт недобросовестности должен быть виден.
CREATE TABLE provider_discrepancies (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind        text        NOT NULL,
    provider    text        NOT NULL,
    request_id  text        NOT NULL,
    order_id    text,
    position    integer,
    code        text,
    details     text,
    detected_at timestamptz NOT NULL DEFAULT now(),
    resolved_at timestamptz,
    resolution  text
);

-- Повторное обнаружение того же расхождения не плодит строк: сверка идёт
-- по расписанию и видит одно и то же до тех пор, пока не разберёт.
CREATE UNIQUE INDEX provider_discrepancies_fact_idx
    ON provider_discrepancies (provider, request_id, kind, coalesce(code, ''));

CREATE INDEX provider_discrepancies_open_idx
    ON provider_discrepancies (detected_at) WHERE resolved_at IS NULL;
