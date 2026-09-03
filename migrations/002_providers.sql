-- История обращений к поставщикам.
--
-- Нужна для разрешения неопределённости: неответ поставщика не равен отказу,
-- и без записи попытки повтор не смог бы отличить «не отвечал» от «отказал».
CREATE TABLE delivery_attempts (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_id   text        NOT NULL REFERENCES orders (id),
    provider   text        NOT NULL,
    request_id text        NOT NULL,
    attempt_no integer     NOT NULL,
    outcome    text        NOT NULL,
    reason     text,
    http_code  integer,
    latency_ms integer,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX delivery_attempts_order_idx ON delivery_attempts (order_id, created_at);

-- Неразрешённые исходы: попытка, после которой неизвестно, выдал поставщик
-- код или нет. Переключение на резервного поставщика до их разрешения
-- привело бы к повторной выдаче.
CREATE INDEX delivery_attempts_unknown_idx ON delivery_attempts (order_id)
    WHERE outcome = 'unknown';

-- Настройки поведения заглушек.
--
-- Доли отказов и неответов задаются на поставщика, чтобы сценарии
-- воспроизводились без правки кода. Режим отличный от random делает
-- поведение детерминированным — это требуется тестам.
CREATE TABLE provider_settings (
    provider     text PRIMARY KEY,
    mode         text    NOT NULL DEFAULT 'random',
    fail_rate    real    NOT NULL DEFAULT 0 CHECK (fail_rate BETWEEN 0 AND 1),
    timeout_rate real    NOT NULL DEFAULT 0 CHECK (timeout_rate BETWEEN 0 AND 1),
    hang_seconds integer NOT NULL DEFAULT 5 CHECK (hang_seconds >= 0),
    updated_at   timestamptz NOT NULL DEFAULT now()
);

-- Выдача может завершиться неопределённостью: поставщик не ответил.
-- Состояние отличается от failed тем, что переключаться на резервного
-- поставщика из него нельзя.
ALTER TABLE deliveries ADD COLUMN unresolved_provider text;
