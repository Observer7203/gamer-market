<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Позиция заказа: единица выдачи.
 *
 * Каждая позиция проходит свой путь независимо от остальных и может
 * завершиться иначе: одна выдана, другая возвращена. Состояние заказа —
 * производная от состояний позиций, а не наоборот.
 */
final class OrderItem
{
    public const PENDING      = 'pending';
    public const DELIVERING   = 'delivering';
    public const DELIVERED    = 'delivered';
    public const OUT_OF_STOCK = 'out_of_stock';
    public const FAILED       = 'failed';

    /**
     * Поставщик не ответил. Возврат из этого состояния запрещён: код мог быть
     * выдан, и возврат денег вместе с выданным товаром — прямой убыток.
     * Сначала неопределённость разрешается повтором к тому же поставщику.
     */
    public const UNRESOLVED   = 'unresolved';

    public const REFUNDED     = 'refunded';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::PENDING      => [self::DELIVERING],
        self::DELIVERING   => [self::DELIVERED, self::OUT_OF_STOCK, self::FAILED, self::UNRESOLVED],

        // Восстановимые: повтор идёт с тем же идентификатором запроса,
        // поэтому возврат в работу не создаёт вторую выдачу.
        self::OUT_OF_STOCK => [self::DELIVERING, self::REFUNDED],
        self::FAILED       => [self::DELIVERING, self::REFUNDED],
        self::UNRESOLVED   => [self::DELIVERING, self::DELIVERED],

        self::DELIVERED    => [],
        self::REFUNDED     => [],
    ];

    /** Состояния, в которых позиция считается завершённой. */
    private const SETTLED = [self::DELIVERED, self::REFUNDED];

    /** Состояния, из которых допустим возврат денег. */
    private const REFUNDABLE = [self::OUT_OF_STOCK, self::FAILED];

    private function __construct(
        public readonly string $orderId,
        public readonly int $position,
        public readonly string $sku,
        public readonly int $priceMinor,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?string $settledAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['order_id'],
            (int) $row['position'],
            (string) $row['sku'],
            (int) $row['price_minor'],
            (string) $row['currency'],
            (string) $row['status'],
            isset($row['settled_at']) ? (string) $row['settled_at'] : null,
        );
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, self::SETTLED, true);
    }

    public function isRefundable(): bool
    {
        return in_array($this->status, self::REFUNDABLE, true);
    }

    public function isDelivered(): bool
    {
        return $this->status === self::DELIVERED;
    }

    /** Идентификатор запроса к поставщику: детерминирован, общий для повторов. */
    public function requestId(string $provider): string
    {
        return sprintf('req_%s_%d_%s', $this->orderId, $this->position, $provider);
    }

    /** @return list<string> состояния, в которых позиция ещё в работе */
    public static function unsettledStatuses(): array
    {
        return [self::PENDING, self::DELIVERING, self::OUT_OF_STOCK, self::FAILED, self::UNRESOLVED];
    }
}
