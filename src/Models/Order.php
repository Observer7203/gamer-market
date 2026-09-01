<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Заказ: состояние и правила его изменения.
 *
 * Переходы заданы белым списком — разрешено только перечисленное. Проверка
 * принадлежит объекту, а не контроллеру или сервису: состоянием владеет заказ.
 */
final class Order
{
    public const CREATED         = 'created';
    public const PAID            = 'paid';
    public const DELIVERING      = 'delivering';
    public const DELIVERED       = 'delivered';
    public const PAYMENT_FAILED  = 'payment_failed';
    public const OUT_OF_STOCK    = 'out_of_stock';
    public const DELIVERY_FAILED = 'delivery_failed';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::CREATED         => [self::PAID, self::PAYMENT_FAILED],
        self::PAID            => [self::DELIVERING],
        self::DELIVERING      => [self::DELIVERED, self::OUT_OF_STOCK, self::DELIVERY_FAILED],

        self::DELIVERED       => [],
        self::PAYMENT_FAILED  => [],

        // Восстановимые: повторная выдача идёт с тем же request_id,
        // поэтому возврат в delivering не создаёт вторую выдачу.
        self::OUT_OF_STOCK    => [self::DELIVERING],
        self::DELIVERY_FAILED => [self::DELIVERING],
    ];

    private const FINAL = [self::DELIVERED, self::PAYMENT_FAILED];

    private function __construct(
        public readonly string $id,
        public readonly string $sku,
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
            (string) $row['sku'],
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

    public function matches(int $amountMinor, string $currency): bool
    {
        return $this->priceMinor === $amountMinor && $this->currency === $currency;
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
