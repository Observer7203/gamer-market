<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Models\PaymentEvent;
use App\Support\Money;

/**
 * Обработчик вебхука платёжной системы.
 *
 * Запись события и его применение выполняются одной транзакцией. Если бы
 * дедупликация коммитилась отдельно, падение между двумя коммитами привело бы
 * к тому, что повторная доставка была бы отброшена как дубликат, а заказ
 * остался неисполненным.
 */
final class HandlePaymentWebhook
{
    public function __construct(
        private readonly Connection $db,
        private readonly ApplyPaymentEvents $applyPaymentEvents,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return string исход обработки
     */
    public function __invoke(array $payload): string
    {
        return $this->db->transaction(function (Connection $db) use ($payload): string {
            $inserted = $db->execute(
                'INSERT INTO payment_events (event_id, order_id, status, amount_minor, currency, occurred_at)
                      VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (event_id) DO NOTHING',
                [
                    $payload['event_id'],
                    $payload['order_id'],
                    $payload['status'],
                    // Граница системы: контракт оперирует целыми рублями,
                    // внутри всё в минорных единицах.
                    Money::toMinor((int) $payload['amount']),
                    $payload['currency'],
                    $payload['created_at'],
                ]
            );

            // Ноль затронутых строк — событие уже принято ранее.
            if ($inserted === 0) {
                return 'duplicate';
            }

            $row = $db->selectOne('SELECT * FROM payment_events WHERE event_id = ?', [$payload['event_id']]);

            return $this->applyPaymentEvents->apply(PaymentEvent::fromRow($row ?? []));
        });
    }
}
