<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Событие платёжной системы.
 *
 * occurredAt — время у платёжной системы, оно задаёт порядок событий.
 * Порядок доставки с ним не связан.
 */
final class PaymentEvent
{
    public const PAID   = 'paid';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $eventId,
        public readonly string $orderId,
        public readonly string $status,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $occurredAt,
        public readonly ?string $processedAt,
        public readonly ?string $outcome,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['event_id'],
            (string) $row['order_id'],
            (string) $row['status'],
            (int) $row['amount_minor'],
            (string) $row['currency'],
            (string) $row['occurred_at'],
            isset($row['processed_at']) ? (string) $row['processed_at'] : null,
            isset($row['outcome']) ? (string) $row['outcome'] : null,
        );
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }
}
