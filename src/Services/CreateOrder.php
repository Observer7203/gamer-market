<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Exceptions\ProductNotFound;
use App\Models\Order;
use App\Support\Logger;

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
        private readonly Logger $logger,
    ) {
    }

    public function __invoke(string $sku): Order
    {
        $product = $this->db->selectOne(
            'SELECT sku, price_minor, currency FROM products WHERE sku = ? AND is_active',
            [$sku]
        );

        if ($product === null) {
            throw new ProductNotFound($sku);
        }

        $id = Order::newId();

        return $this->db->transaction(function (Connection $db) use ($id, $product): Order {
            $db->execute(
                'INSERT INTO orders (id, sku, price_minor, currency, status) VALUES (?, ?, ?, ?, ?)',
                [$id, $product['sku'], $product['price_minor'], $product['currency'], Order::CREATED]
            );

            // Событие оплаты могло прийти до создания заказа и остаться
            // необработанным. Применяем его здесь же, в той же транзакции.
            ($this->applyPaymentEvents)($id);

            $order = Order::fromRow($db->selectOne('SELECT * FROM orders WHERE id = ?', [$id]) ?? []);

            $this->logger->info('order_created', [
                'order_id'     => $order->id,
                'sku'          => $order->sku,
                'amount_minor' => $order->priceMinor,
                'currency'     => $order->currency,
                'status'       => $order->status,
            ]);

            return $order;
        });
    }
}
