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
 * Исполнителя определяет условное обновление строки выдачи: ноль затронутых
 * строк означает, что работу ведёт кто-то другой. Явных блокировок чтением
 * нет — очередь ожидания на одной строке при полусотне исполнителей
 * приводила к взаимным блокировкам.
 *
 * Ключевое правило обработки отказов: отсутствие ответа не равнозначно
 * отказу. Поставщик мог выдать код, ответ мог не дойти. Поэтому:
 *
 *   - повтор идёт к тому же поставщику с тем же request_id, и по контракту
 *     возвращает уже выданный код, а не занимает второй;
 *   - переключение на резервного поставщика допустимо только после явного
 *     ответа предыдущего. Молчание основанием не является: обращение
 *     к резервному создало бы вторую выдачу.
 */
final class DeliverOrder
{
    /** @param array<string, string> $providers порядок задаёт приоритет */
    public function __construct(
        private readonly Connection $db,
        private readonly ProviderClient $provider,
        private readonly Logger $logger,
        private readonly array $providers = ['a', 'b'],
        private readonly int $maxAttempts = 3,
        private readonly int $backoffMs = 200,
    ) {
    }

    /** @return string исход: delivered | out_of_stock | failed | unresolved | noop */
    public function __invoke(string $orderId): string
    {
        $claim = $this->claim($orderId);

        if ($claim === null) {
            $this->logger->info('delivery_skipped', ['order_id' => $orderId]);

            return 'noop';
        }

        $result = $this->obtainCode($orderId, $claim['sku']);

        return $this->store($orderId, $result);
    }

    /**
     * Получение кода: повторы у поставщика, затем переключение на резервного.
     *
     * @return array{outcome: string, provider: string, request_id: string, code: ?string, reason: ?string}
     */
    private function obtainCode(string $orderId, string $sku): array
    {
        $last = null;

        foreach ($this->providers as $provider) {
            // request_id детерминирован и выводится из пары «заказ — поставщик».
            // Повтор обращается к тому же запросу и получает тот же код.
            $requestId = 'req_' . $orderId . '_' . $provider;

            for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
                $response = $this->provider->issue($provider, $requestId, $sku, $orderId);
                $this->recordAttempt($orderId, $provider, $requestId, $attempt, $response);

                $last = [
                    'outcome'    => $response['outcome'],
                    'provider'   => $provider,
                    'request_id' => $requestId,
                    'code'       => $response['code'],
                    'reason'     => $response['reason'],
                ];

                if ($response['outcome'] === ProviderClient::OK) {
                    return $last;
                }

                // Явный отказ: состояние поставщика известно, выдачи не было.
                // Дальнейшие повторы к нему бессмысленны, переключаемся.
                if ($response['outcome'] === ProviderClient::ERROR) {
                    continue 2;
                }

                // Ответа нет. Повторяем к тому же поставщику с тем же
                // идентификатором: если код был выдан, он вернётся.
                if ($attempt < $this->maxAttempts) {
                    usleep($this->backoffMs * (2 ** ($attempt - 1)) * 1000);
                }
            }

            // Повторы исчерпаны, ответа так и не было. Переключение
            // на резервного поставщика запрещено: неизвестно, выдал ли код
            // текущий. Задача вернётся в очередь и повторит обращение
            // к нему же с тем же идентификатором.
            $this->logger->error('delivery_unresolved', [
                'order_id'   => $orderId,
                'provider'   => $provider,
                'request_id' => $requestId,
                'attempts'   => $this->maxAttempts,
            ]);

            return ['outcome' => ProviderClient::UNKNOWN, 'provider' => $provider,
                    'request_id' => $requestId, 'code' => null, 'reason' => 'timeout'];
        }

        return $last ?? ['outcome' => ProviderClient::ERROR, 'provider' => '',
                         'request_id' => '', 'code' => null, 'reason' => 'no_providers'];
    }

    /**
     * Занять слот выдачи и перевести заказ в delivering.
     *
     * Решение принимает один условный оператор: ноль затронутых строк
     * означает, что работу ведёт другой исполнитель либо заказ в конечном
     * состоянии.
     *
     * @return array{sku: string}|null
     */
    private function claim(string $orderId): ?array
    {
        return $this->db->transaction(function (Connection $db) use ($orderId): ?array {
            $db->execute(
                'INSERT INTO deliveries (order_id, status) VALUES (?, ?)
                 ON CONFLICT (order_id) DO NOTHING',
                [$orderId, Delivery::PENDING]
            );

            $claimed = $db->selectOne(
                'UPDATE deliveries d
                    SET status = ?, attempts = d.attempts + 1
                   FROM orders o
                  WHERE d.order_id = ? AND o.id = d.order_id
                    AND d.status IN (?, ?, ?, ?)
                    AND o.status NOT IN (?, ?)
              RETURNING o.sku',
                [
                    Delivery::IN_FLIGHT, $orderId,
                    Delivery::PENDING, Delivery::FAILED, Delivery::OUT_OF_STOCK, Delivery::UNRESOLVED,
                    Order::DELIVERED, Order::PAYMENT_FAILED,
                ]
            );

            if ($claimed === null) {
                return null;
            }

            $db->execute(
                'UPDATE orders SET status = ? WHERE id = ? AND status IN (?, ?, ?)',
                [Order::DELIVERING, $orderId, Order::PAID, Order::OUT_OF_STOCK, Order::DELIVERY_FAILED]
            );

            return ['sku' => (string) $claimed['sku']];
        });
    }

    /** @param array<string, mixed> $result */
    private function store(string $orderId, array $result): string
    {
        return $this->db->transaction(function (Connection $db) use ($orderId, $result): string {
            // Порядок изменения строк совпадает с порядком в claim:
            // сначала выдача, затем заказ.
            if ($result['outcome'] === ProviderClient::OK) {
                $db->execute(
                    'UPDATE deliveries
                        SET status = ?, provider = ?, request_id = ?, code = ?,
                            last_error = NULL, unresolved_provider = NULL, delivered_at = now()
                      WHERE order_id = ?',
                    [Delivery::DELIVERED, $result['provider'], $result['request_id'], $result['code'], $orderId]
                );
                $db->execute(
                    'UPDATE orders SET status = ?, delivered_at = now() WHERE id = ?',
                    [Order::DELIVERED, $orderId]
                );

                $this->logger->info('order_delivered', [
                    'order_id'   => $orderId,
                    'provider'   => $result['provider'],
                    'request_id' => $result['request_id'],
                ]);

                return 'delivered';
            }

            // Ответа не было. Состояние поставщика неизвестно, заказ остаётся
            // в состоянии, из которого повтор пойдёт к нему же.
            if ($result['outcome'] === ProviderClient::UNKNOWN) {
                $db->execute(
                    'UPDATE deliveries
                        SET status = ?, provider = ?, request_id = ?,
                            unresolved_provider = ?, last_error = ?
                      WHERE order_id = ?',
                    [Delivery::UNRESOLVED, $result['provider'], $result['request_id'],
                     $result['provider'], (string) $result['reason'], $orderId]
                );
                $db->execute(
                    'UPDATE orders SET status = ? WHERE id = ? AND status = ?',
                    [Order::DELIVERY_FAILED, $orderId, Order::DELIVERING]
                );

                return 'unresolved';
            }

            // Пустой остаток — восстановимое состояние, а не отказ приложения.
            $outOfStock = ($result['reason'] ?? null) === 'out_of_stock';
            $deliveryStatus = $outOfStock ? Delivery::OUT_OF_STOCK : Delivery::FAILED;
            $orderStatus = $outOfStock ? Order::OUT_OF_STOCK : Order::DELIVERY_FAILED;

            $db->execute(
                'UPDATE deliveries
                    SET status = ?, provider = ?, request_id = ?, last_error = ?,
                        unresolved_provider = NULL
                  WHERE order_id = ?',
                [$deliveryStatus, $result['provider'], $result['request_id'],
                 (string) ($result['reason'] ?? 'unknown'), $orderId]
            );
            $db->execute('UPDATE orders SET status = ? WHERE id = ?', [$orderStatus, $orderId]);

            $this->logger->error('delivery_failed', [
                'order_id'   => $orderId,
                'provider'   => $result['provider'],
                'request_id' => $result['request_id'],
                'reason'     => $result['reason'] ?? 'unknown',
            ]);

            return $deliveryStatus;
        });
    }

    /** @param array<string, mixed> $response */
    private function recordAttempt(
        string $orderId,
        string $provider,
        string $requestId,
        int $attempt,
        array $response
    ): void {
        $this->db->execute(
            'INSERT INTO delivery_attempts
                    (order_id, provider, request_id, attempt_no, outcome, reason, http_code, latency_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $orderId, $provider, $requestId, $attempt,
                $response['outcome'], $response['reason'],
                $response['http'] ?? null, $response['latency_ms'] ?? null,
            ]
        );
    }
}
