-- Ограничение частоты обращений к поставщику.
--
-- Поставщик принимает ограниченное число запросов в минуту. Превышать нельзя,
-- но и терять заказы нельзя: при исчерпании лимита работа откладывается,
-- а не отбрасывается.

CREATE TABLE provider_rate_limits (
    provider         text    PRIMARY KEY,
    limit_per_minute integer NOT NULL CHECK (limit_per_minute > 0),
    window_seconds   integer NOT NULL DEFAULT 60 CHECK (window_seconds > 0),
    updated_at       timestamptz NOT NULL DEFAULT now()
);

-- Журнал обращений: по нему считается окно и проверяется, что лимит
-- не превышен.
--
-- Хранить счётчик вместо журнала было бы дешевле, но проверить по счётчику
-- ничего нельзя: он показывает текущее значение, а не историю. Журнал
-- отвечает на вопрос «сколько было обращений в любую минуту прошлого»,
-- и именно этот вопрос задаёт проверка.
CREATE TABLE provider_calls (
    id        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    provider  text        NOT NULL,
    called_at timestamptz NOT NULL DEFAULT now()
);

-- Выборка обращений за окно: единственный запрос, который делает ограничитель.
CREATE INDEX provider_calls_window_idx ON provider_calls (provider, called_at DESC);

INSERT INTO provider_rate_limits (provider, limit_per_minute) VALUES
    ('a', 60),
    ('b', 60);

-- Приоритет задачи.
--
-- Под всплеском очередь длиннее, чем пропускная способность поставщика,
-- и порядок перестаёт быть безразличным. Оплаченный заказ — обязательство
-- перед покупателем, оно обслуживается раньше фоновой работы.
ALTER TABLE jobs ADD COLUMN priority integer NOT NULL DEFAULT 0;

-- Порядок выборки задач: сначала приоритет, потом срок запуска.
DROP INDEX IF EXISTS jobs_pending_idx;
CREATE INDEX jobs_pending_idx ON jobs (priority DESC, run_at) WHERE status = 'pending';

-- Существующие задачи выдачи относятся к оплаченным заказам.
UPDATE jobs SET priority = 100 WHERE type = 'deliver_item';
