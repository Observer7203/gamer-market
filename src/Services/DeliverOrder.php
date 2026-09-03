<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Models\Delivery;
use App\Models\Order;
use App\Support\Logger;

/**
 * Выдача товара по оплаченному заказу.
 *
 * Обращение к поставщику вынесено между двумя транзакциями: удерживать
 * блокировку строки на время сетевого вызова недопустимо.
 *
 * Первая транзакция занимает слот выдачи, вторая записывает результат.
 * Исполнителя определяет условное обновление строки выдачи: ноль затронутых
 * строк означает, что работу ведёт кто-то другой.
 *
 * Обе транзакции изменяют строки в одном порядке — выдача, затем заказ.
 * Явных блокировок чтением здесь нет: очередь ожидания на одной строке
 * при полусотне исполнителей приводила к взаимным блокировкам.
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
     * Явной блокировки строки заказа здесь нет. Пятьдесят исполнителей,
     * одновременно запросивших SELECT ... FOR UPDATE по одной строке,
     * образуют очередь ожидания, при переупорядочивании которой PostgreSQL
     * замыкает цикл через блокировки кортежа и прерывает транзакции.
     *
     * Вместо этого решение принимает один оператор: условное обновление
     * строки выдачи с присоединением заказа. Ноль затронутых строк означает,
     * что работу уже ведёт другой исполнитель либо заказ в конечном
     * состоянии. Оператор атомарен, очередь ожидания не образуется.
     *
     * @return array{sku: string, request_id: string}|null
     */
    private function claim(string $orderId): ?array
    {
        return $this->db->transaction(function (Connection $db) use ($orderId): ?array {
            // request_id детерминирован: повтор обращается к тому же запросу
            // у поставщика и получает тот же код, а не новый.
            $requestId = 'req_' . $orderId . '_' . self::PROVIDER;

            $db->execute(
                'INSERT INTO deliveries (order_id, status) VALUES (?, ?)
                 ON CONFLICT (order_id) DO NOTHING',
                [$orderId, Delivery::PENDING]
            );

            $claimed = $db->selectOne(
                'UPDATE deliveries d
                    SET status = ?, attempts = d.attempts + 1,
                        provider = ?, request_id = ?
                   FROM orders o
                  WHERE d.order_id = ? AND o.id = d.order_id
                    AND d.status IN (?, ?, ?)
                    AND o.status NOT IN (?, ?)
              RETURNING o.sku',
                [
                    Delivery::IN_FLIGHT, self::PROVIDER, $requestId, $orderId,
                    Delivery::PENDING, Delivery::FAILED, Delivery::OUT_OF_STOCK,
                    Order::DELIVERED, Order::PAYMENT_FAILED,
                ]
            );

            if ($claimed === null) {
                return null;
            }

            // Переход в delivering выполняется условием в самом операторе:
            // отдельная проверка состояния потребовала бы чтения строки,
            // а вместе с ним и блокировки.
            $db->execute(
                'UPDATE orders SET status = ? WHERE id = ? AND status IN (?, ?, ?)',
                [
                    Order::DELIVERING, $orderId,
                    Order::PAID, Order::OUT_OF_STOCK, Order::DELIVERY_FAILED,
                ]
            );

            return ['sku' => (string) $claimed['sku'], 'request_id' => $requestId];
        });
    }

    /** @param array<string, mixed> $response */
    private function store(string $orderId, string $requestId, array $response): string
    {
        return $this->db->transaction(function (Connection $db) use ($orderId, $requestId, $response): string {
            // Порядок изменения строк совпадает с порядком в claim: сначала
            // выдача, затем заказ. Расхождение порядка приводило к взаимной
            // блокировке при конкурентных исполнителях.
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
