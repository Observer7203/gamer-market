<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Витрина каталога: постраничный вывод по ключу, отбор, остатки.
 */
final class CatalogTest extends TestCase
{
    /**
     * Наполняет каталог позициями с убывающим рангом.
     *
     * Товар из общего набора удаляется: проверяется порядок и постраничный
     * вывод, и посторонняя позиция сместила бы ожидаемые границы.
     */
    private function seedProducts(int $count, string $type = 'key'): void
    {
        $this->db->execute('DELETE FROM provider_stock');
        $this->db->execute('DELETE FROM products');

        for ($i = 1; $i <= $count; $i++) {
            $this->db->execute(
                'INSERT INTO products (sku, name, type, price_minor, currency, sort_rank, is_active)
                      VALUES (?, ?, ?, ?, ?, ?, ?)',
                [sprintf('CAT-%04d', $i), "Товар $i", $type, 100000 + $i, 'RUB', $count - $i, 1]
            );
        }
    }

    private function addCodes(string $sku, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->db->execute(
                'INSERT INTO provider_stock (provider, sku, code) VALUES (?, ?, ?)',
                ['a', $sku, sprintf('%s-%03d', $sku, $i)]
            );
        }
    }

    public function testВитринаОтдаётПозицииПоУбываниюРанга(): void
    {
        $this->seedProducts(5);

        $items = $this->request('GET', '/api/catalog', ['limit' => 3])->body['items'];

        self::assertCount(3, $items);
        self::assertSame('CAT-0001', $items[0]['sku'], 'первым идёт наибольший ранг');
        self::assertSame('CAT-0003', $items[2]['sku']);
    }

    public function testОстатокБерётсяИзСчётчика(): void
    {
        $this->seedProducts(1);
        $this->addCodes('CAT-0001', 7);

        $items = $this->request('GET', '/api/catalog', [])->body['items'];
        $product = current(array_filter($items, static fn (array $i): bool => $i['sku'] === 'CAT-0001'));

        self::assertSame(7, $product['available']);
    }

    public function testВыдачаКодаУменьшаетОстаток(): void
    {
        $this->seedProducts(1);
        $this->addCodes('CAT-0001', 3);

        $this->db->execute(
            "UPDATE provider_stock SET request_id = 'req_test'
              WHERE sku = 'CAT-0001' AND id = (SELECT min(id) FROM provider_stock WHERE sku = 'CAT-0001')"
        );

        // Счётчик поддерживается триггером в той же транзакции.
        self::assertSame(2, $this->stockOf('CAT-0001'));
    }

    public function testОсвобождениеКодаВосстанавливаетОстаток(): void
    {
        $this->seedProducts(1);
        $this->addCodes('CAT-0001', 3);

        // Каждому коду нужен свой идентификатор запроса: пара
        // (поставщик, запрос) уникальна, один запрос не занимает три кода.
        $this->db->execute(
            "UPDATE provider_stock SET request_id = 'req_' || id WHERE sku = 'CAT-0001'"
        );
        self::assertSame(0, $this->stockOf('CAT-0001'));

        $this->db->execute("UPDATE provider_stock SET request_id = NULL WHERE sku = 'CAT-0001'");
        self::assertSame(3, $this->stockOf('CAT-0001'));
    }

    public function testСнятыеСПродажиНеПоказываются(): void
    {
        $this->seedProducts(3);
        $this->db->execute("UPDATE products SET is_active = false WHERE sku = 'CAT-0002'");

        $skus = array_column($this->request('GET', '/api/catalog', [])->body['items'], 'sku');

        self::assertNotContains('CAT-0002', $skus);
    }

    public function testОтборПоТипу(): void
    {
        $this->seedProducts(3, 'key');
        $this->db->execute(
            'INSERT INTO products (sku, name, type, price_minor, currency, sort_rank)
                  VALUES (?, ?, ?, ?, ?, ?)',
            ['CAT-SUB-1', 'Подписка', 'subscription', 50000, 'RUB', 99]
        );

        $items = $this->request('GET', '/api/catalog', ['type' => 'subscription'])->body['items'];

        self::assertCount(1, $items);
        self::assertSame('CAT-SUB-1', $items[0]['sku']);
    }

    public function testОтборПоНаличию(): void
    {
        $this->seedProducts(3);
        $this->addCodes('CAT-0002', 5);

        $items = $this->request('GET', '/api/catalog', ['in_stock' => 1])->body['items'];
        $skus = array_column($items, 'sku');

        self::assertSame(['CAT-0002'], $skus);
    }

    public function testПостраничныйВыводПоКлючу(): void
    {
        $this->seedProducts(10);

        $first = $this->request('GET', '/api/catalog', ['limit' => 4])->body;
        self::assertNotNull($first['next_cursor']);
        self::assertCount(4, $first['items']);

        $second = $this->request('GET', '/api/catalog', [
            'limit' => 4,
            'after' => $first['next_cursor'],
        ])->body;

        $firstSkus = array_column($first['items'], 'sku');
        $secondSkus = array_column($second['items'], 'sku');

        self::assertSame([], array_intersect($firstSkus, $secondSkus), 'страницы не пересекаются');
        self::assertSame('CAT-0005', $secondSkus[0], 'вторая страница продолжает первую');
    }

    public function testПоследняяСтраницаБезКурсора(): void
    {
        $this->seedProducts(3);

        $page = $this->request('GET', '/api/catalog', ['limit' => 10])->body;

        self::assertCount(3, $page['items']);
        self::assertNull($page['next_cursor']);
    }

    public function testОбходВсегоКаталогаБезПропусковИПовторов(): void
    {
        $this->seedProducts(25);

        $collected = [];
        $cursor = null;

        do {
            $page = $this->request('GET', '/api/catalog', [
                'limit' => 7,
                'after' => $cursor,
            ])->body;

            $collected = [...$collected, ...array_column($page['items'], 'sku')];
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);

        self::assertCount(25, $collected);
        self::assertCount(25, array_unique($collected), 'повторов нет');
    }

    public function testНекорректныйКурсорНеЛомаетЗапрос(): void
    {
        $this->seedProducts(3);

        $page = $this->request('GET', '/api/catalog', ['after' => 'мусор'])->body;

        self::assertCount(3, $page['items'], 'запрос выполняется с начала');
    }

    public function testРазмерСтраницыОграничен(): void
    {
        $this->seedProducts(5);

        $page = $this->request('GET', '/api/catalog', ['limit' => 10000])->body;

        self::assertCount(5, $page['items']);
    }

    private function stockOf(string $sku): int
    {
        return (int) $this->db->selectOne(
            'SELECT available_count FROM products WHERE sku = ?', [$sku]
        )['available_count'];
    }
}
