-- Журнал денежных движений: двойная запись.
--
-- Каждая проводка состоит минимум из двух строк, сумма которых равна нулю:
-- деньги не возникают и не исчезают, а перемещаются между счетами.
-- Знак задаёт направление: положительное значение — приход на счёт,
-- отрицательное — расход.
--
-- Баланс не хранится отдельной колонкой. Он вычисляется суммой строк:
-- хранимое значение было бы вторым источником правды и могло разойтись
-- с движениями, а сверять его стало бы не с чем.
CREATE TABLE ledger_entries (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    txn_id       text        NOT NULL,
    order_id     text        NOT NULL REFERENCES orders (id),
    account      text        NOT NULL,
    amount_minor bigint      NOT NULL CHECK (amount_minor <> 0),
    currency     text        NOT NULL,
    reason       text        NOT NULL,
    created_at   timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX ledger_entries_txn_idx     ON ledger_entries (txn_id);
CREATE INDEX ledger_entries_order_idx   ON ledger_entries (order_id);
CREATE INDEX ledger_entries_account_idx ON ledger_entries (account, created_at);

-- Одна проводка на одно событие: повторная запись того же факта невозможна.
CREATE UNIQUE INDEX ledger_entries_txn_account_idx ON ledger_entries (txn_id, account);

-- Проверка баланса проводки.
--
-- Ограничение CHECK охватывает одну строку и для этого непригодно: сумма
-- проводки складывается из нескольких строк. Отложенный триггер проверяет
-- её в момент фиксации транзакции, когда все строки уже вставлены.
CREATE FUNCTION ledger_txn_balanced() RETURNS trigger AS $$
DECLARE
    txn text := coalesce(NEW.txn_id, OLD.txn_id);
    imbalance bigint;
BEGIN
    SELECT sum(amount_minor) INTO imbalance
      FROM ledger_entries WHERE txn_id = txn;

    IF imbalance <> 0 THEN
        RAISE EXCEPTION 'Проводка % не сходится: расхождение % минорных единиц', txn, imbalance;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER ledger_entries_balanced
    AFTER INSERT OR UPDATE OR DELETE ON ledger_entries
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION ledger_txn_balanced();

-- Заказы, ожидающие завершения: выборка для сверки и фоновой доводки.
CREATE INDEX orders_unsettled_idx ON orders (paid_at)
    WHERE status IN ('paid', 'delivering', 'out_of_stock', 'delivery_failed');
