-- Позиции заказа.
--
-- Заказ перестаёт быть единицей выдачи: каждая позиция выдаётся отдельно,
-- своим поставщиком, и может завершиться независимо от остальных.
--
-- Ключ составной: номер позиции внутри заказа, а не суррогатный
-- идентификатор. Благодаря этому идентификатор запроса к поставщику
-- остаётся детерминированным — req_<заказ>_<позиция>_<поставщик>, — и повтор
-- обращается к тому же запросу, а не создаёт новый.
--
-- Один артикул может встречаться в заказе несколько раз: это разные позиции
-- с разными номерами, каждая со своим кодом.
CREATE TABLE order_items (
    order_id    text    NOT NULL REFERENCES orders (id),
    position    integer NOT NULL CHECK (position > 0),
    sku         text    NOT NULL REFERENCES products (sku),
    price_minor bigint  NOT NULL CHECK (price_minor > 0),
    currency    text    NOT NULL,
    status      text    NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    settled_at  timestamptz,

    PRIMARY KEY (order_id, position)
);

-- Выборка незавершённых позиций для сверки и фоновой доводки.
CREATE INDEX order_items_unsettled_idx ON order_items (order_id)
    WHERE settled_at IS NULL;

CREATE INDEX order_items_status_idx ON order_items (status, created_at);

-- Существующие заказы становятся заказами из одной позиции.
INSERT INTO order_items (order_id, position, sku, price_minor, currency, status, created_at, settled_at)
SELECT o.id, 1, o.sku, o.price_minor, o.currency,
       CASE o.status
           WHEN 'delivered'       THEN 'delivered'
           WHEN 'payment_failed'  THEN 'pending'
           WHEN 'out_of_stock'    THEN 'out_of_stock'
           WHEN 'delivery_failed' THEN 'failed'
           WHEN 'delivering'      THEN 'delivering'
           ELSE 'pending'
       END,
       o.created_at,
       o.delivered_at
  FROM orders o;

-- Выдача привязывается к позиции, а не к заказу.
--
-- Первичный ключ по паре сохраняет прежний инвариант в новом виде:
-- на позицию приходится не более одной выдачи.
ALTER TABLE deliveries ADD COLUMN position integer NOT NULL DEFAULT 1;
ALTER TABLE deliveries DROP CONSTRAINT deliveries_pkey;
ALTER TABLE deliveries ADD PRIMARY KEY (order_id, position);
ALTER TABLE deliveries ALTER COLUMN position DROP DEFAULT;

-- Момент занятия позиции в работу. По нему восстановитель отличает
-- выдачу, которая действительно идёт, от брошенной упавшим исполнителем.
ALTER TABLE deliveries ADD COLUMN claimed_at timestamptz;

ALTER TABLE deliveries
    ADD CONSTRAINT deliveries_item_fkey
    FOREIGN KEY (order_id, position) REFERENCES order_items (order_id, position);

-- История обращений к поставщику тоже ведётся по позициям.
ALTER TABLE delivery_attempts ADD COLUMN position integer NOT NULL DEFAULT 1;
ALTER TABLE delivery_attempts ALTER COLUMN position DROP DEFAULT;

-- Состав переехал в позиции: артикул на уровне заказа потерял смысл.
ALTER TABLE orders DROP COLUMN sku;

-- Сумма заказа хранится как есть и равна сумме позиций. Ограничение
-- проверяется отложенно: строки позиций вставляются после строки заказа.
CREATE FUNCTION order_total_matches_items() RETURNS trigger AS $$
DECLARE
    order_key text := coalesce(NEW.order_id, OLD.order_id);
    expected  bigint;
    actual    bigint;
BEGIN
    SELECT price_minor INTO expected FROM orders WHERE id = order_key;

    -- Заказ мог быть удалён вместе с позициями.
    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT coalesce(sum(price_minor), 0) INTO actual
      FROM order_items WHERE order_id = order_key;

    IF actual <> expected THEN
        RAISE EXCEPTION 'Сумма заказа % не равна сумме позиций: % против %',
            order_key, expected, actual;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER order_items_total_check
    AFTER INSERT OR UPDATE OR DELETE ON order_items
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION order_total_matches_items();
