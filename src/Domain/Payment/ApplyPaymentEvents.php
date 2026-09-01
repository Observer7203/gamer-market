<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Database\Connection;
use App\Domain\Ordering\Order;
use App\Queue\Queue;
use App\Support\Logger;

/**
 * Применение платёжных событий к заказу.
 *
 * Вынесено из обработчика вебхука, потому что вызывается из двух мест:
 * при доставке события и при создании заказа, к которому событие пришло
 * раньше, чем сам заказ.
 *
 * Порядок определяется полем occurred_at платёжной системы, а не порядком
 * доставки: событие, созданное раньше уже применённого, не отменяет его.
 */
final class ApplyPaymentEvents
{
    public function __construct(
        private readonly Connection $db,
        private readonly Queue $queue,
        private readonly Logger $logger,
    ) {
    }

    /** Применяет все необработанные события заказа. Вызывается внутри транзакции. */
    public function __invoke(string $orderId): void
    {
        $events = $this->db->select(
            'SELECT * FROM payment_events
              WHERE order_id = ? AND processed_at IS NULL
              ORDER BY occurred_at, received_at',
            [$orderId]
        );

        foreach ($events as $event) {
            $this->apply($event);
        }
    }

    /** @param array<string, mixed> $event */
    public function apply(array $event): string
    {
        $orderId = (string) $event['order_id'];

        $order = $this->db->selectOne('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);

        if ($order === null) {
            // Заказа ещё нет. Событие остаётся необработанным и будет применено
            // при его создании — не ошибка и не потеря.
            return $this->finish($event, 'order_not_found', processed: false);
        }

        if ((int) $event['amount'] !== (int) $order['price'] || $event['currency'] !== $order['currency']) {
            // Расхождение суммы разбирается человеком через сверку.
            // Ответ платёжной системе всё равно успешный: повтор его не исправит.
            return $this->finish($event, 'amount_mismatch');
        }

        if ($this->supersededBy($event)) {
            return $this->finish($event, 'superseded');
        }

        $target = $event['status'] === 'paid' ? Order::PAID : Order::PAYMENT_FAILED;

        if (!Order::canTransition((string) $order['status'], $target)) {
            // Повтор оплаты по уже выданному заказу — штатная ситуация,
            // а не ошибка: платёжная система доставляет события повторно.
            return $this->finish($event, 'ignored');
        }

        if ($target === Order::PAID) {
            $this->db->execute(
                'UPDATE orders SET status = ?, paid_at = now() WHERE id = ?',
                [Order::PAID, $orderId]
            );

            // Задача создаётся той же транзакцией, что и смена статуса:
            // состояние «оплачен, но задачи нет» недостижимо.
            $this->queue->push('deliver_order', ['order_id' => $orderId]);
        } else {
            $this->db->execute('UPDATE orders SET status = ? WHERE id = ?', [Order::PAYMENT_FAILED, $orderId]);
        }

        return $this->finish($event, 'applied');
    }

    /** Есть ли уже применённое событие, созданное позже этого. */
    private function supersededBy(array $event): bool
    {
        $newer = $this->db->selectOne(
            'SELECT 1 FROM payment_events
              WHERE order_id = ? AND event_id <> ? AND outcome = \'applied\'
                AND occurred_at > ?
              LIMIT 1',
            [$event['order_id'], $event['event_id'], $event['occurred_at']]
        );

        return $newer !== null;
    }

    /** @param array<string, mixed> $event */
    private function finish(array $event, string $outcome, bool $processed = true): string
    {
        $this->db->execute(
            $processed
                ? 'UPDATE payment_events SET outcome = ?, processed_at = now() WHERE event_id = ?'
                : 'UPDATE payment_events SET outcome = ? WHERE event_id = ?',
            [$outcome, $event['event_id']]
        );

        $this->logger->info('payment_event_applied', [
            'event_id' => $event['event_id'],
            'order_id' => $event['order_id'],
            'status'   => $event['status'],
            'outcome'  => $outcome,
        ]);

        return $outcome;
    }
}
