<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Заказ: состав, сумма и правила изменения состояния.
 *
 * Заказ состоит из позиций, каждая из которых выдаётся отдельно. Конечное
 * состояние заказа выводится из состояний позиций: все выданы, часть выдана
 * и часть возвращена, либо возвращено всё.
 */
final class Order
{
    public const CREATED    = 'created';
    public const PAID       = 'paid';
    public const DELIVERING = 'delivering';

    /** Все позиции выданы. */
    public const DELIVERED = 'delivered';

    /** Часть позиций выдана, за остальные возвращены деньги. */
    public const PARTIALLY_DELIVERED = 'partially_delivered';

    /** Ни одна позиция не выдана, деньги возвращены полностью. */
    public const REFUNDED = 'refunded';

    public const PAYMENT_FAILED = 'payment_failed';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::CREATED    => [self::PAID, self::PAYMENT_FAILED],
        self::PAID       => [self::DELIVERING],
        self::DELIVERING => [self::DELIVERED, self::PARTIALLY_DELIVERED, self::REFUNDED],

        self::DELIVERED           => [],
        self::PARTIALLY_DELIVERED => [],
        self::REFUNDED            => [],
        self::PAYMENT_FAILED      => [],
    ];

    private const FINAL = [
        self::DELIVERED,
        self::PARTIALLY_DELIVERED,
        self::REFUNDED,
        self::PAYMENT_FAILED,
    ];

    private function __construct(
        public readonly string $id,
        public readonly int $priceMinor,
        public readonly string $currency,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly ?string $paidAt,
        public readonly ?string $deliveredAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id'],
            (int) $row['price_minor'],
            (string) $row['currency'],
            (string) $row['status'],
            (string) $row['created_at'],
            isset($row['paid_at']) ? (string) $row['paid_at'] : null,
            isset($row['delivered_at']) ? (string) $row['delivered_at'] : null,
        );
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    public function isPaid(): bool
    {
        return $this->paidAt !== null;
    }

    public function matches(int $amountMinor, string $currency): bool
    {
        return $this->priceMinor === $amountMinor && $this->currency === $currency;
    }

    /**
     * Конечное состояние заказа по итогам позиций.
     *
     * @param int $delivered число выданных позиций
     * @param int $total     всего позиций
     */
    public static function outcomeFor(int $delivered, int $total): string
    {
        return match (true) {
            $delivered === $total => self::DELIVERED,
            $delivered === 0      => self::REFUNDED,
            default               => self::PARTIALLY_DELIVERED,
        };
    }

    /**
     * ULID с префиксом: лексикографически сортируемый и монотонный по времени,
     * поэтому вставки не рандомизируют порядок в btree-индексе.
     */
    public static function newId(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = (int) (microtime(true) * 1000);

        $id = '';
        for ($i = 9; $i >= 0; $i--) {
            $id = $alphabet[$time % 32] . $id;
            $time = intdiv($time, 32);
        }
        for ($i = 0; $i < 16; $i++) {
            $id .= $alphabet[random_int(0, 31)];
        }

        return 'ord_' . $id;
    }
}
