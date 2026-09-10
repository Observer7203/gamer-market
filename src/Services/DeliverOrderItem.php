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
 *
 * Второе правило: ответу поставщика верить нельзя. Присланный код проходит
 * приёмку и становится нашим только там; отказ проверяется запросом статуса,
 * потому что поставщик мог выдать код и ответить ошибкой.
 */
final class DeliverOrderItem
{
    /**
     * Исход «места в лимите поставщика нет». Не ошибка и не отказ: обращения
     * не было, и повторять его нужно позже, а не считать попыткой.
     */
    public const THROTTLED = 'throttled';

    /**
     * Состояние выдачи, соответствующее состоянию позиции. Нужно при возврате
     * позиции в прежнее состояние: обе строки описывают один и тот же факт.
     *
     * @var array<string, string>
     */
    private const DELIVERY_STATE = [
        OrderItem::PENDING      => Delivery::PENDING,
        OrderItem::OUT_OF_STOCK => Delivery::OUT_OF_STOCK,
        OrderItem::FAILED       => Delivery::FAILED,
        OrderItem::UNRESOLVED   => Delivery::UNRESOLVED,
    ];

    /** @param list<string> $providers порядок задаёт приоритет */
    public function __construct(
        private readonly Connection $db,
        private readonly ProviderClient $provider,
        private readonly Ledger $ledger,
        private readonly RefundItem $refundItem,
        private readonly FinalizeOrder $finalizeOrder,
        private readonly AcceptProviderCode $acceptCode,
        private readonly Discrepancies $discrepancies,
        private readonly ProviderRateLimiter $rateLimiter,
        private readonly Logger $logger,
        private readonly array $providers = ['a', 'b'],
        private readonly int $maxAttempts = 3,
        private readonly int $backoffMs = 200,
        private readonly int $giveUpAfter = 3,
    ) {
    }

    /** @return string исход: delivered | refunded | out_of_stock | failed | unresolved | throttled | noop */
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

        $result = $this->obtainCode(
            $orderId,
            $position,
            (string) $claim['sku'],
            (int) $claim['generation'],
        );

        // Лимит поставщика исчерпан: обращения не было, состояние позиции
        // не изменилось. Освобождаем её и откладываем работу.
        if ($result['outcome'] === self::THROTTLED) {
            $this->release($orderId, $position, (string) $claim['previous']);

            return self::THROTTLED;
        }

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
     * @return array{sku: string, attempts: int, generation: int, previous: string}|null
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
              RETURNING i.sku, d.attempts, d.generation, i.status AS previous',
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

            return [
                'sku'        => (string) $claimed['sku'],
                'attempts'   => (int) $claimed['attempts'],
                'generation' => (int) $claimed['generation'],
                // Состояние до захвата: строка order_items этим оператором
                // не менялась, поэтому RETURNING отдаёт прежнее значение.
                'previous'   => (string) $claimed['previous'],
            ];
        });
    }

    /**
     * Возврат позиции в прежнее состояние.
     *
     * Обращения к поставщику не было, значит и попытки не было: счётчик
     * возвращается назад. Иначе всплеск исчерпал бы бюджет попыток
     * и позиции, которые ничем не больны, ушли бы в возврат денег.
     */
    private function release(string $orderId, int $position, string $previous): void
    {
        $this->db->transaction(function (Connection $db) use ($orderId, $position, $previous): void {
            $db->execute(
                'UPDATE deliveries
                    SET status = ?, attempts = greatest(0, attempts - 1)
                  WHERE order_id = ? AND position = ? AND status = ?',
                [self::DELIVERY_STATE[$previous] ?? Delivery::PENDING, $orderId, $position, Delivery::IN_FLIGHT]
            );

            $db->execute(
                'UPDATE order_items SET status = ? WHERE order_id = ? AND position = ? AND status = ?',
                [$previous, $orderId, $position, OrderItem::DELIVERING]
            );
        });

        $this->logger->info('delivery_deferred', [
            'channel'  => 'delivery',
            'order_id' => $orderId,
            'position' => $position,
            'previous' => $previous,
        ]);
    }

    /**
     * Получение кода: повторы у поставщика, затем переключение на резервного.
     *
     * @return array{outcome: string, provider: string, request_id: string, code: ?string, reason: ?string}
     */
    private function obtainCode(string $orderId, int $position, string $sku, int $generation): array
    {
        $rejected = false;
        $throttled = false;
        $called = false;
        $last = null;

        foreach ($this->providers as $provider) {
            $requestId = OrderItem::requestIdFor($orderId, $position, $provider, $generation);

            for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
                // Место в лимите занимается перед каждым обращением, а не раз
                // на заход: повторы — такие же запросы к поставщику.
                if (!$this->rateLimiter->acquire($provider)) {
                    $throttled = true;

                    // К этому поставщику ещё не обращались: можно попробовать
                    // следующего, у него свой лимит.
                    if ($attempt === 1) {
                        continue 2;
                    }

                    // Обращение уже было и осталось без ответа. Переключаться
                    // нельзя, а ждать места — значит держать позицию занятой.
                    // Оставляем неопределённость, повтор разберёт её позже.
                    break;
                }

                $called = true;
                $response = $this->provider->issue($provider, $requestId, $sku, $orderId);
                $this->recordAttempt($orderId, $position, $provider, $requestId, $attempt, $generation, $response);

                if ($response['outcome'] === ProviderClient::OK) {
                    $accepted = ($this->acceptCode)(
                        $provider,
                        $requestId,
                        $orderId,
                        $position,
                        (string) $response['code'],
                    );

                    // Код принадлежит другой позиции. Тот же запрос будет
                    // возвращать его и дальше, поэтому нужен новый запрос:
                    // поколение растёт, и поставщик получает шанс исправиться.
                    if ($accepted['outcome'] === AcceptProviderCode::DUPLICATE) {
                        $rejected = true;
                        $generation = $this->nextGeneration($orderId, $position);
                        $requestId = OrderItem::requestIdFor($orderId, $position, $provider, $generation);

                        continue;
                    }

                    $rejected = false;

                    return ['outcome' => ProviderClient::OK, 'provider' => $provider,
                            'request_id' => $requestId, 'code' => $accepted['code'], 'reason' => null];
                }

                // Явный отказ. Верить ему нельзя: поставщик мог выдать код.
                if ($response['outcome'] === ProviderClient::ERROR) {
                    $verified = $this->verify($provider, $requestId, $orderId, $position, (string) $response['reason']);

                    if ($verified !== null) {
                        return $verified;
                    }

                    // Причина отказа сохраняется: пустой остаток — состояние
                    // восстановимое, и позиция должна знать об этом, даже если
                    // так ответили все поставщики.
                    $last = ['outcome' => ProviderClient::ERROR, 'provider' => $provider,
                             'request_id' => $requestId, 'code' => null, 'reason' => $response['reason']];

                    continue 2;
                }

                // Ответа нет. Повторяем к тому же поставщику с тем же
                // идентификатором: если код был выдан, он вернётся.
                if ($attempt < $this->maxAttempts) {
                    usleep($this->backoffMs * (2 ** ($attempt - 1)) * 1000);
                }
            }

            // Поставщик отвечал, но каждый раз чужим кодом. Состояние его
            // известно: выдачи, принадлежащей нам, не было. Значит переключение
            // на резервного допустимо — в отличие от молчания.
            if ($rejected) {
                $this->logger->error('delivery_codes_rejected', [
                    'channel'    => 'delivery',
                    'order_id'   => $orderId,
                    'position'   => $position,
                    'provider'   => $provider,
                    'attempts'   => $this->maxAttempts,
                ]);

                continue;
            }

            // Повторы исчерпаны, ответа так и не было. Прежде чем оставлять
            // позицию в неопределённости, спрашиваем поставщика напрямую:
            // выдача могла состояться, а ответ не дойти.
            $verified = $this->verify($provider, $requestId, $orderId, $position, 'timeout');

            if ($verified !== null) {
                return $verified;
            }

            // Переключение на резервного поставщика запрещено: неизвестно,
            // выдал ли код текущий.
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

        // Ни одного обращения не состоялось: у всех поставщиков нет места.
        // Позиция не тронута, работа откладывается.
        if (!$called && $throttled) {
            return ['outcome' => self::THROTTLED, 'provider' => '', 'request_id' => '',
                    'code' => null, 'reason' => 'rate_limited'];
        }

        if ($rejected) {
            return ['outcome' => ProviderClient::ERROR, 'provider' => '', 'request_id' => '',
                    'code' => null, 'reason' => 'code_rejected'];
        }

        return $last ?? ['outcome' => ProviderClient::ERROR, 'provider' => '',
                         'request_id' => '', 'code' => null, 'reason' => 'no_providers'];
    }

    /**
     * Проверка отрицательного ответа запросом состояния.
     *
     * Поставщик мог выдать код и ответить отказом либо промолчать. Отказ —
     * заявление, состояние на его стороне — факт, и расходятся они регулярно.
     *
     * @return array{outcome: string, provider: string, request_id: string,
     *               code: ?string, reason: ?string}|null null, если выдачи не было
     */
    private function verify(
        string $provider,
        string $requestId,
        string $orderId,
        int $position,
        string $reason
    ): ?array {
        // Запрос состояния — такое же обращение к поставщику и место в лимите
        // занимает наравне с выдачей.
        if (!$this->rateLimiter->acquire($provider)) {
            return null;
        }

        $status = $this->provider->status($provider, $requestId);

        if ($status['outcome'] !== ProviderClient::OK || $status['code'] === null) {
            return null;
        }

        $accepted = ($this->acceptCode)($provider, $requestId, $orderId, $position, (string) $status['code']);

        if ($accepted['outcome'] === AcceptProviderCode::DUPLICATE) {
            return null;
        }

        // Ответ поставщика разошёлся с действительностью. Расхождение
        // записывается и тут же закрывается: разбор произошёл сам.
        $this->discrepancies->record(
            Discrepancies::SILENT_ISSUE,
            $provider,
            $requestId,
            $orderId,
            $position,
            $accepted['code'],
            sprintf('ответ %s, код выдан', $reason),
        );
        $this->discrepancies->resolve(
            Discrepancies::SILENT_ISSUE,
            $provider,
            $requestId,
            'код принят по состоянию поставщика',
        );

        return ['outcome' => ProviderClient::OK, 'provider' => $provider,
                'request_id' => $requestId, 'code' => $accepted['code'], 'reason' => null];
    }

    /** Следующее поколение запроса: прежний запрос отравлен чужим кодом. */
    private function nextGeneration(string $orderId, int $position): int
    {
        $row = $this->db->selectOne(
            'UPDATE deliveries SET generation = generation + 1
              WHERE order_id = ? AND position = ?
          RETURNING generation',
            [$orderId, $position]
        );

        return (int) ($row['generation'] ?? 1);
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
        int $generation,
        array $response
    ): void {
        $this->db->execute(
            'INSERT INTO delivery_attempts
                    (order_id, position, provider, request_id, attempt_no, generation,
                     outcome, reason, http_code, latency_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $orderId, $position, $provider, $requestId, $attempt, $generation,
                $response['outcome'], $response['reason'],
                $response['http'] ?? null, $response['latency_ms'] ?? null,
            ]
        );
    }
}
