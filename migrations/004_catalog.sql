-- Витрина каталога.
--
-- Порядок вывода задаётся вручную, а не алфавитом или датой добавления:
-- на первых позициях показывают то, что продаётся.
ALTER TABLE products ADD COLUMN sort_rank integer NOT NULL DEFAULT 0;

-- Остаток, денормализованный из складов поставщиков.
--
-- Считать его на лету подзапросом по provider_stock нельзя: коррелированный
-- подзапрос выполняется для каждой строки-кандидата, и на тысячах позиций
-- при сотнях тысяч кодов запрос витрины перестаёт укладываться в отведённое
-- время. Значение поддерживается триггером в той же транзакции, что и выдача
-- кода, поэтому разойтись с реальным остатком не может.
ALTER TABLE products ADD COLUMN available_count integer NOT NULL DEFAULT 0
    CHECK (available_count >= 0);

-- Поддержание остатка.
--
-- Свободным считается код без привязки к запросу. Изменение затрагивает
-- строку каталога, поэтому выполняется одним оператором без предварительного
-- чтения: очередь ожидания на популярной позиции недопустима.
CREATE FUNCTION product_stock_sync() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.request_id IS NULL THEN
            UPDATE products SET available_count = available_count + 1 WHERE sku = NEW.sku;
        END IF;

    ELSIF TG_OP = 'DELETE' THEN
        IF OLD.request_id IS NULL THEN
            UPDATE products SET available_count = available_count - 1 WHERE sku = OLD.sku;
        END IF;

    ELSE
        -- Код ушёл в запрос: остаток уменьшился.
        IF OLD.request_id IS NULL AND NEW.request_id IS NOT NULL THEN
            UPDATE products SET available_count = available_count - 1 WHERE sku = OLD.sku;
        END IF;

        -- Код освобождён: остаток вырос.
        IF OLD.request_id IS NOT NULL AND NEW.request_id IS NULL THEN
            UPDATE products SET available_count = available_count + 1 WHERE sku = NEW.sku;
        END IF;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER provider_stock_sync_products
    AFTER INSERT OR UPDATE OF request_id OR DELETE ON provider_stock
    FOR EACH ROW EXECUTE FUNCTION product_stock_sync();

-- Приведение остатка в соответствие с уже загруженным складом.
UPDATE products p
   SET available_count = (
       SELECT count(*) FROM provider_stock s
        WHERE s.sku = p.sku AND s.request_id IS NULL
   );

-- Покрывающие индексы витрины.
--
-- Ключ повторяет порядок сортировки, включённые колонки покрывают весь набор
-- выводимых полей: обращения к самой таблице не происходит, выполняется
-- сканирование только по индексу.
--
-- Условие is_active сокращает индекс до продаваемых позиций: снятые с продажи
-- в него не попадают и места не занимают.
CREATE INDEX products_showcase_idx
    ON products (sort_rank DESC, sku DESC)
    INCLUDE (name, type, price_minor, currency, available_count)
    WHERE is_active;

-- Отдельный индекс для отбора по типу: без типа в ключе выборка одного
-- раздела витрины потребовала бы чтения всего индекса.
CREATE INDEX products_showcase_type_idx
    ON products (type, sort_rank DESC, sku DESC)
    INCLUDE (name, price_minor, currency, available_count)
    WHERE is_active;
