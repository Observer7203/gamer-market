<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Logger;

/**
 * Выдача одной позиции заказа.
 *
 * Единица работы — позиция, а не заказ: соседние позиции обрабатываются
 * независимо и могут завершиться по-разному. Провал одной не отменяет
 * выданные и не блокирует остальные.
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
 *     ответа предыдущего;
 *   - возврат денег из неопределённости запрещён: сначала она разрешается.
 */
final class DeliverOrderItem
{
    /** @param list<string> $providers порядок задаёт приоритет */
    public function __construct(
        private readonly Connection $db,
        private readonly ProviderClient $provider,
        private readonly Ledger $ledger,
        private readonly RefundItem $refundItem,
        private readonly FinalizeOrder $finalizeOrder,
        private readonly Logger $logger,
        private readonly array $providers = ['a', 'b'],
        private readonly int $maxAttempts = 3,
        private readonly int $backoffMs = 200,
        private readonly int $giveUpAfter = 3,
    ) {
    }

    /** @return string исход: delivered | refunded | out_of_stock | failed | unresolved | noop */
    public function __invoke(string $orderId, int $position): string
    {
        $claim = $this->claim($orderId, $position);

        if ($claim === null) {
            $this->logger->info('delivery_skipped', [
                'channel'  => 'delivery',
                'order_id' => $orderId,
                'position' => $position,
            ]);

            return 'noop';
        }

        $result = $this->obtainCode($orderId, $position, (string) $claim['sku']);
        $outcome = $this->store($orderId, $position, $result);

        if ($outcome === 'delivered') {
            ($this->finalizeOrder)($orderId);

            return $outcome;
        }

        // Состояние поставщика неизвестно: возврат запрещён, задача
        // возвращается в очередь и повторит обращение к нему же.
        if ($outcome === OrderItem::UNRESOLVED) {
            return 'unresolved';
        }

        // Поставщики ответили отказом, бюджет попыток исчерпан. Дальнейшие
        // повторы ничего не изменят — деньги за позицию возвращаются.
        if ((int) $claim['attempts'] >= $this->giveUpAfter && ($this->refundItem)($orderId, $position)) {
            return 'refunded';
        }

        return $outcome;
    }

    /**
     * Занять позицию в работу.
     *
     * Одно условное обновление отвечает сразу на три вопроса: не занята ли
     * позиция другим исполнителем, оплачен ли заказ и не завершена ли она уже.
     *
     * @return array{sku: string, attempts: int}|null
     */
    private function claim(string $orderId, int $position): ?array
    {
        return $this->db->transaction(function (Connection $db) use ($orderId, $position): ?array {
            $db->execute(
                'INSERT INTO deliveries (order_id, position, status) VALUES (?, ?, ?)
                 ON CONFLICT (order_id, position) DO NOTHING',
                [$orderId, $position, Delivery::PENDING]
            );

            $claimed = $db->selectOne(
                'UPDATE deliveries d
                    SET status = ?, attempts = d.attempts + 1, claimed_at = now()
                   FROM order_items i, orders o
                  WHERE d.order_id = ? AND d.position = ?
                    AND i.order_id = d.order_id AND i.position = d.position
                    AND o.id = d.order_id
                    AND d.status <> ?
                    AND i.status IN (?, ?, ?, ?)
                    AND o.status IN (?, ?)
              RETURNING i.sku, d.attempts',
                [
                    Delivery::IN_FLIGHT, $orderId, $position,
                    Delivery::IN_FLIGHT,
                    OrderItem::PENDING, OrderItem::OUT_OF_STOCK, OrderItem::FAILED, OrderItem::UNRESOLVED,
                    Order::PAID, Order::DELIVERING,
                ]
            );

            if ($claimed === null) {
                return null;
            }

            $db->execute(
                'UPDATE order_items SET status = ? WHERE order_id = ? AND position = ?',
                [OrderItem::DELIVERING, $orderId, $position]
            );

            $db->execute(
                'UPDATE orders SET status = ? WHERE id = ? AND status = ?',
                [Order::DELIVERING, $orderId, Order::PAID]
            );

            return ['sku' => (string) $claimed['sku'], 'attempts' => (int) $claimed['attempts']];
        });
    }

    /**
     * Получение кода: повторы у поставщика, затем переключение на резервного.
     *
     * @return array{outcome: string, provider: string, request_id: string, code: ?string, reason: ?string}
     */
    private function obtainCode(string $orderId, int $position, string $sku): array
    {
        $last = null;

        foreach ($this->providers as $provider) {
            // request_id детерминирован и выводится из тройки
            // «заказ — позиция — поставщик»: повтор обращается
            // к тому же запросу и получает тот же код.
            $requestId = sprintf('req_%s_%d_%s', $orderId, $position, $provider);

            for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
                $response = $this->provider->issue($provider, $requestId, $sku, $orderId);
                $this->recordAttempt($orderId, $position, $provider, $requestId, $attempt, $response);

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
            // текущий.
            $this->logger->error('delivery_unresolved', [
                'channel'    => 'delivery',
                'order_id'   => $orderId,
                'position'   => $position,
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
     * @param array<string, mixed> $result
     * @return string delivered | out_of_stock | failed | unresolved
     */
    private function store(string $orderId, int $position, array $result): string
    {
        return $this->db->transaction(function (Connection $db) use ($orderId, $position, $result): string {
            if ($result['outcome'] === ProviderClient::OK) {
                $db->execute(
                    'UPDATE deliveries
                        SET status = ?, provider = ?, request_id = ?, code = ?,
                            last_error = NULL, unresolved_provider = NULL, delivered_at = now()
                      WHERE order_id = ? AND position = ?',
                    [Delivery::DELIVERED, $result['provider'], $result['request_id'],
                     $result['code'], $orderId, $position]
                );

                $item = $db->selectOne(
                    'UPDATE order_items
                        SET status = ?, settled_at = now()
                      WHERE order_id = ? AND position = ? AND status = ?
                  RETURNING price_minor, currency',
                    [OrderItem::DELIVERED, $orderId, $position, OrderItem::DELIVERING]
                );

                if ($item !== null) {
                    // Обязательство перед покупателем по этой позиции
                    // исполнено: выручка признана.
                    $this->ledger->recordDelivery(
                        $orderId,
                        $position,
                        (int) $item['price_minor'],
                        (string) $item['currency'],
                    );
                }

                $this->logger->info('item_delivered', [
                    'channel'    => 'delivery',
                    'order_id'   => $orderId,
                    'position'   => $position,
                    'provider'   => $result['provider'],
                    'request_id' => $result['request_id'],
                ]);

                return 'delivered';
            }

            if ($result['outcome'] === ProviderClient::UNKNOWN) {
                $db->execute(
                    'UPDATE deliveries
                        SET status = ?, provider = ?, request_id = ?,
                            unresolved_provider = ?, last_error = ?
                      WHERE order_id = ? AND position = ?',
                    [Delivery::UNRESOLVED, $result['provider'], $result['request_id'],
                     $result['provider'], (string) $result['reason'], $orderId, $position]
                );
                $db->execute(
                    'UPDATE order_items SET status = ? WHERE order_id = ? AND position = ? AND status = ?',
                    [OrderItem::UNRESOLVED, $orderId, $position, OrderItem::DELIVERING]
                );

                return OrderItem::UNRESOLVED;
            }

            // Пустой остаток — восстановимое состояние, а не отказ приложения.
            $outOfStock = ($result['reason'] ?? null) === 'out_of_stock';
            $status = $outOfStock ? OrderItem::OUT_OF_STOCK : OrderItem::FAILED;

            $db->execute(
                'UPDATE deliveries
                    SET status = ?, provider = ?, request_id = ?, last_error = ?,
                        unresolved_provider = NULL
                  WHERE order_id = ? AND position = ?',
                [$outOfStock ? Delivery::OUT_OF_STOCK : Delivery::FAILED,
                 $result['provider'], $result['request_id'],
                 (string) ($result['reason'] ?? 'unknown'), $orderId, $position]
            );
            $db->execute(
                'UPDATE order_items SET status = ? WHERE order_id = ? AND position = ? AND status = ?',
                [$status, $orderId, $position, OrderItem::DELIVERING]
            );

            $this->logger->error('delivery_failed', [
                'channel'    => 'delivery',
                'order_id'   => $orderId,
                'position'   => $position,
                'provider'   => $result['provider'],
                'request_id' => $result['request_id'],
                'reason'     => $result['reason'] ?? 'unknown',
            ]);

            return $status;
        });
    }

    /** @param array<string, mixed> $response */
    private function recordAttempt(
        string $orderId,
        int $position,
        string $provider,
        string $requestId,
        int $attempt,
        array $response
    ): void {
        $this->db->execute(
            'INSERT INTO delivery_attempts
                    (order_id, position, provider, request_id, attempt_no, outcome, reason, http_code, latency_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $orderId, $position, $provider, $requestId, $attempt,
                $response['outcome'], $response['reason'],
                $response['http'] ?? null, $response['latency_ms'] ?? null,
            ]
        );
    }
}
