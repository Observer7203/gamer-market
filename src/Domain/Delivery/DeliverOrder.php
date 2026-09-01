<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use App\Database\Connection;
use App\Models\Delivery;
use App\Models\Order;
use App\Support\Logger;

/**
 * Выдача товара по оплаченному заказу.
 *
 * Обращение к поставщику вынесено между двумя транзакциями: удерживать
 * блокировку строки заказа на время сетевого вызова недопустимо.
 *
 * Первая транзакция занимает слот выдачи, вторая записывает результат.
 * Слот занимается условным UPDATE: если строка уже в работе или выдана,
 * затронуто ноль строк и второй исполнитель прекращает работу.
 */
final class DeliverOrder
{
    private const PROVIDER = 'a';

    public function __construct(
        private readonly Connection $db,
        private readonly ProviderClient $provider,
        private readonly Logger $logger,
    ) {
    }

    /** @return string исход: delivered | out_of_stock | failed | noop */
    public function __invoke(string $orderId): string
    {
        $claim = $this->claim($orderId);

        if ($claim === null) {
            $this->logger->info('delivery_skipped', ['order_id' => $orderId]);

            return 'noop';
        }

        $response = $this->provider->issue($claim['request_id'], $claim['sku'], $orderId);

        return $this->store($orderId, $claim['request_id'], $response);
    }

    /**
     * Занять слот выдачи и перевести заказ в delivering.
     *
     * @return array{sku: string, request_id: string}|null
     */
    private function claim(string $orderId): ?array
    {
        return $this->db->transaction(function (Connection $db) use ($orderId): ?array {
            $row = $db->selectOne('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);

            if ($row === null) {
                return null;
            }

            $order = Order::fromRow($row);

            if ($order->isFinal()) {
                return null;
            }

            // request_id детерминирован: повтор обращается к тому же запросу
            // у поставщика и получает тот же код, а не новый.
            $requestId = 'req_' . $orderId . '_' . self::PROVIDER;

            $db->execute(
                'INSERT INTO deliveries (order_id, status) VALUES (?, ?)
                 ON CONFLICT (order_id) DO NOTHING',
                [$orderId, Delivery::PENDING]
            );

            $taken = $db->execute(
                'UPDATE deliveries
                    SET status = ?, attempts = attempts + 1,
                        provider = ?, request_id = ?
                  WHERE order_id = ? AND status IN (?, ?, ?)',
                [
                    Delivery::IN_FLIGHT, self::PROVIDER, $requestId, $orderId,
                    Delivery::PENDING, Delivery::FAILED, Delivery::OUT_OF_STOCK,
                ]
            );

            if ($taken === 0) {
                return null;
            }

            if ($order->canTransitionTo(Order::DELIVERING)) {
                $db->execute('UPDATE orders SET status = ? WHERE id = ?', [Order::DELIVERING, $orderId]);
            }

            return ['sku' => $order->sku, 'request_id' => $requestId];
        });
    }

    /** @param array<string, mixed> $response */
    private function store(string $orderId, string $requestId, array $response): string
    {
        return $this->db->transaction(function (Connection $db) use ($orderId, $requestId, $response): string {
            if ($response['outcome'] === 'ok') {
                $db->execute(
                    'UPDATE deliveries SET status = ?, code = ?, last_error = NULL,
                            delivered_at = now() WHERE order_id = ?',
                    [Delivery::DELIVERED, $response['code'], $orderId]
                );
                $db->execute(
                    'UPDATE orders SET status = ?, delivered_at = now() WHERE id = ?',
                    [Order::DELIVERED, $orderId]
                );

                $this->logger->info('order_delivered', [
                    'order_id'   => $orderId,
                    'request_id' => $requestId,
                ]);

                return 'delivered';
            }

            // Пустой остаток — восстановимое состояние, а не отказ приложения.
            $outOfStock = ($response['reason'] ?? null) === 'out_of_stock';
            $deliveryStatus = $outOfStock ? Delivery::OUT_OF_STOCK : Delivery::FAILED;
            $orderStatus = $outOfStock ? Order::OUT_OF_STOCK : Order::DELIVERY_FAILED;

            $db->execute(
                'UPDATE deliveries SET status = ?, last_error = ? WHERE order_id = ?',
                [$deliveryStatus, (string) ($response['reason'] ?? 'unknown'), $orderId]
            );
            $db->execute('UPDATE orders SET status = ? WHERE id = ?', [$orderStatus, $orderId]);

            $this->logger->error('delivery_failed', [
                'order_id'   => $orderId,
                'request_id' => $requestId,
                'reason'     => $response['reason'] ?? 'unknown',
            ]);

            return $deliveryStatus;
        });
    }
}
