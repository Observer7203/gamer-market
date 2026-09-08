<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Exceptions\ProductNotFound;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Logger;

/**
 * Создание заказа из одной или нескольких позиций.
 *
 * Сумма каждой позиции берётся из каталога и копируется в заказ: клиент
 * присылает только артикулы, а последующая переоценка товара не меняет
 * сумму уже оформленного заказа.
 *
 * Номер позиции присваивается по порядку и в дальнейшем не меняется:
 * на нём построен детерминированный идентификатор запроса к поставщику.
 */
final class CreateOrder
{
    private const MAX_ITEMS = 50;

    public function __construct(
        private readonly Connection $db,
        private readonly ApplyPaymentEvents $applyPaymentEvents,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param list<string> $skus артикулы в порядке следования позиций
     * @return array{order: Order, items: list<OrderItem>}
     */
    public function __invoke(array $skus): array
    {
        if ($skus === [] || count($skus) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(
                sprintf('Заказ должен содержать от 1 до %d позиций', self::MAX_ITEMS)
            );
        }

        $products = $this->lookup($skus);
        $id = Order::newId();
        $total = array_sum(array_map(
            static fn (string $sku): int => (int) $products[$sku]['price_minor'],
            $skus
        ));
        $currency = (string) $products[$skus[0]]['currency'];

        return $this->db->transaction(function (Connection $db) use ($id, $skus, $products, $total, $currency): array {
            $db->execute(
                'INSERT INTO orders (id, price_minor, currency, status) VALUES (?, ?, ?, ?)',
                [$id, $total, $currency, Order::CREATED]
            );

            foreach ($skus as $index => $sku) {
                $db->execute(
                    'INSERT INTO order_items (order_id, position, sku, price_minor, currency, status)
                          VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $index + 1, $sku, $products[$sku]['price_minor'],
                     $products[$sku]['currency'], OrderItem::PENDING]
                );
            }

            // Событие оплаты могло прийти до создания заказа и остаться
            // необработанным. Применяем его здесь же, в той же транзакции.
            ($this->applyPaymentEvents)($id);

            $this->logger->info('order_created', [
                'channel'      => 'order',
                'order_id'     => $id,
                'items'        => count($skus),
                'amount_minor' => $total,
                'currency'     => $currency,
            ]);

            return $this->load($db, $id);
        });
    }

    /**
     * @param list<string> $skus
     * @return array<string, array<string, mixed>>
     */
    private function lookup(array $skus): array
    {
        $unique = array_values(array_unique($skus));
        $placeholders = implode(', ', array_fill(0, count($unique), '?'));

        $rows = $this->db->select(
            "SELECT sku, price_minor, currency FROM products
              WHERE sku IN ($placeholders) AND is_active",
            $unique
        );

        $found = [];
        foreach ($rows as $row) {
            $found[(string) $row['sku']] = $row;
        }

        foreach ($unique as $sku) {
            if (!isset($found[$sku])) {
                throw new ProductNotFound($sku);
            }
        }

        // Валюты позиций обязаны совпадать: заказ оплачивается одной суммой.
        if (count(array_unique(array_column($rows, 'currency'))) > 1) {
            throw new \InvalidArgumentException('Позиции заказа должны быть в одной валюте');
        }

        return $found;
    }

    /** @return array{order: Order, items: list<OrderItem>} */
    private function load(Connection $db, string $id): array
    {
        return [
            'order' => Order::fromRow($db->selectOne('SELECT * FROM orders WHERE id = ?', [$id]) ?? []),
            'items' => array_map(
                OrderItem::fromRow(...),
                $db->select('SELECT * FROM order_items WHERE order_id = ? ORDER BY position', [$id])
            ),
        ];
    }
}
