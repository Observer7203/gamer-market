<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Queue\Queue;
use App\Services\Ledger;
use App\Support\Logger;

/**
 * Применение платёжных событий к заказу.
 *
 * Вызывается из двух мест: при доставке события и при создании заказа,
 * к которому событие пришло раньше, чем сам заказ.
 *
 * Порядок определяется полем occurredAt платёжной системы, а не порядком
 * доставки: событие, созданное раньше уже применённого, не отменяет его.
 */
final class ApplyPaymentEvents
{
    public function __construct(
        private readonly Connection $db,
        private readonly Queue $queue,
        private readonly Ledger $ledger,
        private readonly Logger $logger,
    ) {
    }

    /** Применяет все необработанные события заказа. Вызывается внутри транзакции. */
    public function __invoke(string $orderId): void
    {
        $rows = $this->db->select(
            'SELECT * FROM payment_events
              WHERE order_id = ? AND processed_at IS NULL
              ORDER BY occurred_at, received_at',
            [$orderId]
        );

        foreach ($rows as $row) {
            $this->apply(PaymentEvent::fromRow($row));
        }
    }

    public function apply(PaymentEvent $event): string
    {
        $row = $this->db->selectOne('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$event->orderId]);

        if ($row === null) {
            // Заказа ещё нет. Событие остаётся необработанным и будет применено
            // при его создании — не ошибка и не потеря.
            return $this->finish($event, 'order_not_found', processed: false);
        }

        $order = Order::fromRow($row);

        if (!$order->matches($event->amountMinor, $event->currency)) {
            // Расхождение суммы разбирается человеком через сверку.
            // Ответ платёжной системе всё равно успешный: повтор его не исправит.
            return $this->finish($event, 'amount_mismatch');
        }

        if ($this->supersededBy($event)) {
            return $this->finish($event, 'superseded');
        }

        $target = $event->isPaid() ? Order::PAID : Order::PAYMENT_FAILED;

        if (!$order->canTransitionTo($target)) {
            // Повтор оплаты по уже выданному заказу — штатная ситуация,
            // а не ошибка: платёжная система доставляет события повторно.
            return $this->finish($event, 'ignored');
        }

        if ($target === Order::PAID) {
            $this->db->execute(
                'UPDATE orders SET status = ?, paid_at = now() WHERE id = ?',
                [Order::PAID, $order->id]
            );

            // Задачи и проводка создаются той же транзакцией, что и смена
            // статуса: состояния «оплачен, но задачи нет» и «оплачен,
            // но в журнале пусто» недостижимы.
            //
            // На каждую позицию своя задача: они выполняются независимо
            // и провал одной не мешает остальным.
            foreach ($this->positions($order->id) as $position) {
                $this->queue->push('deliver_item', [
                    'order_id' => $order->id,
                    'position' => $position,
                ]);
            }

            $this->ledger->recordPayment(
                $order->id,
                $event->eventId,
                $event->amountMinor,
                $event->currency,
            );
        } else {
            $this->db->execute('UPDATE orders SET status = ? WHERE id = ?', [Order::PAYMENT_FAILED, $order->id]);
        }

        return $this->finish($event, 'applied');
    }

    /** @return list<int> номера позиций заказа */
    private function positions(string $orderId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['position'],
            $this->db->select('SELECT position FROM order_items WHERE order_id = ? ORDER BY position', [$orderId])
        );
    }

    /** Есть ли уже применённое событие, созданное позже этого. */
    private function supersededBy(PaymentEvent $event): bool
    {
        return $this->db->selectOne(
            'SELECT 1 FROM payment_events
              WHERE order_id = ? AND event_id <> ? AND outcome = \'applied\'
                AND occurred_at > ?
              LIMIT 1',
            [$event->orderId, $event->eventId, $event->occurredAt]
        ) !== null;
    }

    private function finish(PaymentEvent $event, string $outcome, bool $processed = true): string
    {
        $this->db->execute(
            $processed
                ? 'UPDATE payment_events SET outcome = ?, processed_at = now() WHERE event_id = ?'
                : 'UPDATE payment_events SET outcome = ? WHERE event_id = ?',
            [$outcome, $event->eventId]
        );

        $this->logger->info('payment_event_applied', [
            'channel'  => 'payment',
            'event_id' => $event->eventId,
            'order_id' => $event->orderId,
            'status'   => $event->status,
            'outcome'  => $outcome,
        ]);

        return $outcome;
    }
}
