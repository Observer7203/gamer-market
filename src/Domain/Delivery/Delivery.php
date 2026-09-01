<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

/**
 * Выдача товара по заказу. Одна на заказ — за это отвечает первичный ключ
 * deliveries.order_id.
 */
final class Delivery
{
    public const PENDING      = 'pending';
    public const IN_FLIGHT    = 'in_flight';
    public const DELIVERED    = 'delivered';
    public const FAILED       = 'failed';
    public const OUT_OF_STOCK = 'out_of_stock';

    private function __construct(
        public readonly string $orderId,
        public readonly string $status,
        public readonly ?string $provider,
        public readonly ?string $requestId,
        public readonly ?string $code,
        public readonly int $attempts,
        public readonly ?string $lastError,
        public readonly ?string $deliveredAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['order_id'],
            (string) $row['status'],
            isset($row['provider']) ? (string) $row['provider'] : null,
            isset($row['request_id']) ? (string) $row['request_id'] : null,
            isset($row['code']) ? (string) $row['code'] : null,
            (int) ($row['attempts'] ?? 0),
            isset($row['last_error']) ? (string) $row['last_error'] : null,
            isset($row['delivered_at']) ? (string) $row['delivered_at'] : null,
        );
    }

    public function isDelivered(): bool
    {
        return $this->status === self::DELIVERED;
    }
}
