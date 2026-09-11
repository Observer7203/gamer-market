-- Журнал состояний заказов и позиций.
--
-- Текущее состояние лежит в orders и order_items и перезаписывается при каждом
-- изменении. По нему нельзя ответить на вопрос «что было вчера в полдень»:
-- прежние значения затёрты.
--
-- История отвечает на этот вопрос и только дополняется. Задним числом в ней
-- ничего не переписывается — за этим следит триггер, а не договорённость.

CREATE TABLE order_events (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_id    text        NOT NULL REFERENCES orders (id),

    -- NULL означает событие заказа целиком, число — событие позиции.
    position    integer,

    from_status text,
    to_status   text        NOT NULL,

    -- Цена позиции на момент события: по истории должно восстанавливаться
    -- и состояние, и деньги, без обращения к текущим строкам.
    amount_minor bigint,

    occurred_at timestamptz NOT NULL DEFAULT now()
);

-- Восстановление состояния на момент: события заказа до указанного времени.
CREATE INDEX order_events_order_idx ON order_events (order_id, occurred_at, id);

-- Итоги за период по всем заказам.
CREATE INDEX order_events_time_idx ON order_events (occurred_at, id);

-- Запрет переписывания.
--
-- Правило «история только дополняется» должно быть свойством таблицы,
-- а не соглашением между разработчиками: соглашение нарушается ошибкой,
-- ограничение не нарушается никак.
CREATE FUNCTION forbid_rewrite() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'История только дополняется: % запрещён для таблицы %',
        TG_OP, TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER order_events_append_only
    BEFORE UPDATE OR DELETE ON order_events
    FOR EACH ROW EXECUTE FUNCTION forbid_rewrite();

-- Журнал денег дополнялся и раньше, но запрета не было.
CREATE TRIGGER ledger_entries_append_only
    BEFORE UPDATE OR DELETE ON ledger_entries
    FOR EACH ROW EXECUTE FUNCTION forbid_rewrite();

-- Запись истории.
--
-- Пишет база, а не приложение. Сервис можно забыть дополнить при добавлении
-- нового пути изменения статуса, и история молча разойдётся с состоянием.
-- Триггер срабатывает на любое изменение, откуда бы оно ни пришло.
CREATE FUNCTION log_order_change() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO order_events (order_id, position, from_status, to_status, amount_minor)
             VALUES (NEW.id, NULL, NULL, NEW.status, NEW.price_minor);
    ELSIF OLD.status IS DISTINCT FROM NEW.status THEN
        INSERT INTO order_events (order_id, position, from_status, to_status, amount_minor)
             VALUES (NEW.id, NULL, OLD.status, NEW.status, NEW.price_minor);
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION log_order_item_change() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO order_events (order_id, position, from_status, to_status, amount_minor)
             VALUES (NEW.order_id, NEW.position, NULL, NEW.status, NEW.price_minor);
    ELSIF OLD.status IS DISTINCT FROM NEW.status THEN
        INSERT INTO order_events (order_id, position, from_status, to_status, amount_minor)
             VALUES (NEW.order_id, NEW.position, OLD.status, NEW.status, NEW.price_minor);
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER orders_history
    AFTER INSERT OR UPDATE ON orders
    FOR EACH ROW EXECUTE FUNCTION log_order_change();

CREATE TRIGGER order_items_history
    AFTER INSERT OR UPDATE ON order_items
    FOR EACH ROW EXECUTE FUNCTION log_order_item_change();

-- Перенос уже существующих заказов.
--
-- Точной истории по ним нет: прежние значения статусов затёрты. Восстановимо
-- то, что осталось в самих строках — моменты создания, оплаты и завершения.
-- Этого хватает, чтобы состояние на любой момент после переноса совпадало
-- с действительностью.
INSERT INTO order_events (order_id, position, from_status, to_status, amount_minor, occurred_at)
SELECT o.id, NULL, NULL, 'created', o.price_minor, o.created_at FROM orders o;

-- greatest, а не сам paid_at: в данных разработки встречаются заказы,
-- которым сценарии проверки сдвигали время оплаты назад. Хронология событий
-- обязана быть неубывающей, иначе последним событием окажется создание.
INSERT INTO order_events (order_id, position, from_status, to_status, amount_minor, occurred_at)
SELECT o.id, NULL, 'created', 'paid', o.price_minor, greatest(o.paid_at, o.created_at)
  FROM orders o WHERE o.paid_at IS NOT NULL;

INSERT INTO order_events (order_id, position, from_status, to_status, amount_minor, occurred_at)
SELECT o.id, NULL, 'paid', o.status, o.price_minor,
       greatest(coalesce(o.delivered_at, o.paid_at, o.created_at), o.paid_at, o.created_at)
  FROM orders o WHERE o.status NOT IN ('created', 'paid');

INSERT INTO order_events (order_id, position, from_status, to_status, amount_minor, occurred_at)
SELECT i.order_id, i.position, NULL, 'pending', i.price_minor, i.created_at FROM order_items i;

INSERT INTO order_events (order_id, position, from_status, to_status, amount_minor, occurred_at)
SELECT i.order_id, i.position, 'pending', i.status, i.price_minor,
       greatest(i.settled_at, i.created_at)
  FROM order_items i WHERE i.settled_at IS NOT NULL;

INSERT INTO order_events (order_id, position, from_status, to_status, amount_minor, occurred_at)
SELECT i.order_id, i.position, 'pending', i.status, i.price_minor, i.created_at
  FROM order_items i WHERE i.settled_at IS NULL AND i.status <> 'pending';
