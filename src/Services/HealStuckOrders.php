<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Queue\Queue;
use App\Support\Logger;

/**
 * Фоновая доводка заказов, не завершившихся самостоятельно.
 *
 * Безопасность обеспечивается не осторожностью действий, а их природой:
 * каждое из них идемпотентно. Возврат задачи в очередь приводит
 * к повторному обращению с тем же идентификатором запроса, а выдачу
 * защищает первичный ключ на заказе. Повторный запуск ничего не удваивает.
 */
final class HealStuckOrders
{
    /** Задача считается брошенной, если исполнитель не завершил её за это время. */
    private const LEASE_SECONDS = 300;

    /** Заказ считается зависшим, если не завершился за это время после оплаты. */
    private const STUCK_AFTER_SECONDS = 120;

    public function __construct(
        private readonly Connection $db,
        private readonly Queue $queue,
        private readonly ApplyPaymentEvents $applyPaymentEvents,
        private readonly FinalizeOrder $finalizeOrder,
        private readonly Logger $logger,
    ) {
    }

    /** @return array<string, int> что и сколько было исправлено */
    public function __invoke(): array
    {
        $healed = [
            'released_jobs'     => $this->releaseAbandonedJobs(),
            'released_items'    => $this->releaseAbandonedItems(),
            'applied_events'    => $this->applyPendingEvents(),
            'requeued_items'    => $this->requeueUnfinishedItems(),
            'finalized_orders'  => $this->finalizeSettledOrders(),
        ];

        if (array_sum($healed) > 0) {
            $this->logger->info('heal_completed', ['channel' => 'queue'] + $healed);
        }

        return $healed;
    }

    /**
     * Возврат задач, взятых в работу и не завершённых.
     *
     * Исполнитель мог быть остановлен посреди обработки. Задача остаётся
     * в состоянии выполнения и без возврата не будет взята повторно.
     */
    private function releaseAbandonedJobs(): int
    {
        return $this->db->execute(
            "UPDATE jobs
                SET status = 'pending', locked_at = NULL, run_at = now()
              WHERE status = 'running' AND locked_at < now() - make_interval(secs => ?)",
            [self::LEASE_SECONDS]
        );
    }

    /**
     * Применение событий, пришедших раньше заказа.
     *
     * Событие по несуществующему заказу сохраняется необработанным.
     * Обычно оно применяется при создании заказа, но заказ мог появиться
     * иным путём.
     */
    private function applyPendingEvents(): int
    {
        $orders = $this->db->select(
            'SELECT DISTINCT e.order_id
               FROM payment_events e
               JOIN orders o ON o.id = e.order_id
              WHERE e.processed_at IS NULL'
        );

        $applied = 0;

        foreach ($orders as $row) {
            $orderId = (string) $row['order_id'];

            $this->db->transaction(function () use ($orderId, &$applied): void {
                ($this->applyPaymentEvents)($orderId);
                $applied++;
            });

            $this->logger->info('heal_applied_event', [
                'channel'  => 'payment',
                'order_id' => $orderId,
            ]);
        }

        return $applied;
    }

    /**
     * Освобождение позиций, брошенных посреди выдачи.
     *
     * Исполнитель мог быть остановлен между занятием позиции и записью
     * результата. Позиция остаётся в состоянии выдачи, и без сброса её
     * не возьмёт ни один исполнитель. Повторное обращение к поставщику
     * идёт с прежним идентификатором запроса, поэтому вторая выдача
     * исключена — сброс безопасен.
     */
    private function releaseAbandonedItems(): int
    {
        $released = $this->db->execute(
            "UPDATE deliveries
                SET status = ?
              WHERE status = ? AND claimed_at < now() - make_interval(secs => ?)",
            [Delivery::UNRESOLVED, Delivery::IN_FLIGHT, self::LEASE_SECONDS]
        );

        $this->db->execute(
            "UPDATE order_items i
                SET status = ?
               FROM deliveries d
              WHERE d.order_id = i.order_id AND d.position = i.position
                AND i.status = ? AND d.status = ?",
            [OrderItem::UNRESOLVED, OrderItem::DELIVERING, Delivery::UNRESOLVED]
        );

        return $released;
    }

    /**
     * Возврат в очередь незавершённых позиций без активной задачи.
     *
     * Покрывает случай, когда задача была утрачена или исчерпала попытки,
     * а позиция осталась неисполненной.
     */
    private function requeueUnfinishedItems(): int
    {
        $items = $this->db->select(
            "SELECT i.order_id, i.position
               FROM order_items i
               JOIN orders o ON o.id = i.order_id
              WHERE i.settled_at IS NULL
                AND o.status IN (?, ?)
                AND o.paid_at < now() - make_interval(secs => ?)
                AND NOT EXISTS (
                    SELECT 1 FROM jobs j
                     WHERE j.status IN ('pending', 'running')
                       AND j.payload->>'order_id' = i.order_id
                       AND (j.payload->>'position')::int = i.position
                )",
            [Order::PAID, Order::DELIVERING, self::STUCK_AFTER_SECONDS]
        );

        foreach ($items as $row) {
            $this->queue->push('deliver_item', [
                'order_id' => (string) $row['order_id'],
                'position' => (int) $row['position'],
            ]);

            $this->logger->info('heal_requeued_item', [
                'channel'  => 'delivery',
                'order_id' => (string) $row['order_id'],
                'position' => (int) $row['position'],
            ]);
        }

        return count($items);
    }

    /**
     * Доведение заказов, все позиции которых завершены, до конечного статуса.
     *
     * Обычно это делает сама выдача, но процесс мог упасть между завершением
     * последней позиции и пересчётом заказа.
     */
    private function finalizeSettledOrders(): int
    {
        $orders = $this->db->select(
            "SELECT o.id
               FROM orders o
              WHERE o.status IN (?, ?)
                AND NOT EXISTS (
                    SELECT 1 FROM order_items i
                     WHERE i.order_id = o.id AND i.settled_at IS NULL
                )",
            [Order::PAID, Order::DELIVERING]
        );

        $finalized = 0;

        foreach ($orders as $row) {
            if (($this->finalizeOrder)((string) $row['id']) !== null) {
                $finalized++;
            }
        }

        return $finalized;
    }
}
