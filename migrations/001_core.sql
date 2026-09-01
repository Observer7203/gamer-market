-- Каталог. SKU — естественный ключ: он же используется в контракте поставщика.
--
-- Денежные величины хранятся в минорных единицах валюты целым числом:
-- копейки, центы. Дробное представление исключено — двоичная дробь
-- не выражает 0.1 точно, и сравнение сумм перестаёт быть надёжным.
-- Мажорные единицы существуют только на границах системы: контракт
-- платёжной системы и ответ API оперируют целыми рублями, преобразование
-- выполняет App\Domain\Money.
--
-- bigint, а не integer: int вмещает 2 147 483 647 копеек, то есть 21.4 млн
-- рублей. Отдельной цены это ограничение не касается, но накопительные
-- величины журнала проводок его превышают. Единый тип по всем денежным
-- колонкам избавляет от приведений при сравнении и суммировании,
-- а смена типа на заполненной таблице означает её перезапись
-- под исключительной блокировкой.
CREATE TABLE products (
    sku         text PRIMARY KEY,
    name        text    NOT NULL,
    type        text    NOT NULL,
    price_minor bigint  NOT NULL CHECK (price_minor > 0),
    currency    text    NOT NULL,
    is_active   boolean NOT NULL DEFAULT true
);

-- Заказ. price_minor и currency скопированы из каталога на момент создания:
-- переоценка товара не должна менять сумму уже оформленного заказа.
-- Ограничение продублировано: копирование выполняет код, и ошибка в нём
-- не должна пройти молча.
CREATE TABLE orders (
    id           text PRIMARY KEY,
    sku          text        NOT NULL REFERENCES products (sku),
    price_minor  bigint      NOT NULL CHECK (price_minor > 0),
    currency     text        NOT NULL,
    status       text        NOT NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    paid_at      timestamptz,
    delivered_at timestamptz
);

CREATE INDEX orders_status_created_at_idx ON orders (status, created_at);

-- События платёжной системы. Первичный ключ по event_id — дедупликация
-- повторной доставки: вторая вставка того же события не проходит.
-- occurred_at приходит от платёжной системы и определяет порядок событий,
-- который может не совпадать с порядком доставки.
CREATE TABLE payment_events (
    event_id     text PRIMARY KEY,
    order_id     text        NOT NULL,
    status       text        NOT NULL,
    amount_minor bigint      NOT NULL,
    currency     text        NOT NULL,
    occurred_at  timestamptz NOT NULL,
    received_at  timestamptz NOT NULL DEFAULT now(),
    processed_at timestamptz,
    outcome      text
);

CREATE INDEX payment_events_order_id_idx ON payment_events (order_id);

-- События по несуществующему заказу: применяются повторно при его появлении.
CREATE INDEX payment_events_unapplied_idx ON payment_events (order_id)
    WHERE processed_at IS NULL;

-- Выдача. UNIQUE (order_id) — инвариант однократной выдачи: вторую строку
-- по заказу вставить невозможно независимо от конкурентности.
CREATE TABLE deliveries (
    order_id     text PRIMARY KEY REFERENCES orders (id),
    status       text        NOT NULL,
    provider     text,
    request_id   text,
    code         text,
    attempts     integer     NOT NULL DEFAULT 0,
    last_error   text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    delivered_at timestamptz
);

-- Очередь фоновых задач в той же базе: задача создаётся одной транзакцией
-- со сменой статуса заказа, поэтому потеря задачи невозможна.
CREATE TABLE jobs (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    type       text        NOT NULL,
    payload    jsonb       NOT NULL,
    status     text        NOT NULL DEFAULT 'pending',
    attempts   integer     NOT NULL DEFAULT 0,
    run_at     timestamptz NOT NULL DEFAULT now(),
    locked_at  timestamptz,
    last_error text,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- Частичный индекс: воркер выбирает только невзятые задачи.
CREATE INDEX jobs_pending_idx ON jobs (run_at) WHERE status = 'pending';
CREATE INDEX jobs_running_idx ON jobs (locked_at) WHERE status = 'running';

-- Склад заглушек-поставщиков. Принадлежит поставщику, а не магазину:
-- по контракту код выдаёт он.
CREATE TABLE provider_stock (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    provider   text NOT NULL,
    sku        text NOT NULL,
    code       text NOT NULL,
    request_id text,
    issued_at  timestamptz,
    UNIQUE (provider, code)
);

-- Повтор с тем же request_id обязан вернуть тот же код: за это отвечает
-- уникальность пары, а не проверка в коде заглушки.
CREATE UNIQUE INDEX provider_stock_request_idx ON provider_stock (provider, request_id)
    WHERE request_id IS NOT NULL;

-- Выборка свободного кода нужного артикула.
CREATE INDEX provider_stock_available_idx ON provider_stock (provider, sku)
    WHERE request_id IS NULL;
