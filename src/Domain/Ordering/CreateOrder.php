<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Database\Connection;
use App\Domain\Payment\ApplyPaymentEvents;

/**
 * Создание заказа по артикулу.
 *
 * Сумма берётся из каталога и копируется в заказ: клиент присылает только
 * артикул, а последующая переоценка товара не должна менять сумму
 * уже оформленного заказа.
 */
final class CreateOrder
{
    public function __construct(
        private readonly Connection $db,
        private readonly ApplyPaymentEvents $applyPaymentEvents,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $sku): array
    {
        $product = $this->db->selectOne(
            'SELECT sku, price_minor, currency FROM products WHERE sku = ? AND is_active',
            [$sku]
        );

        if ($product === null) {
            throw new ProductNotFound($sku);
        }

        $id = Order::newId();

        return $this->db->transaction(function (Connection $db) use ($id, $product): array {
            $db->execute(
                'INSERT INTO orders (id, sku, price_minor, currency, status) VALUES (?, ?, ?, ?, ?)',
                [$id, $product['sku'], $product['price_minor'], $product['currency'], Order::CREATED]
            );

            // Событие оплаты могло прийти до создания заказа и остаться
            // необработанным. Применяем его здесь же, в той же транзакции.
            ($this->applyPaymentEvents)($id);

            return $db->selectOne('SELECT * FROM orders WHERE id = ?', [$id]) ?? [];
        });
    }
}
