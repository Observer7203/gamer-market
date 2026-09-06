<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Models\Order;
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
        private readonly Logger $logger,
    ) {
    }

    /** @return array<string, int> что и сколько было исправлено */
    public function __invoke(): array
    {
        $healed = [
            'released_jobs'    => $this->releaseAbandonedJobs(),
            'applied_events'   => $this->applyPendingEvents(),
            'requeued_orders'  => $this->requeueUnfinishedOrders(),
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
     * Возврат в очередь оплаченных заказов без активной задачи.
     *
     * Покрывает случай, когда задача была утрачена или исчерпала попытки,
     * а заказ остался неисполненным. Повторное обращение к поставщику идёт
     * с прежним идентификатором запроса, поэтому вторая выдача исключена.
     */
    private function requeueUnfinishedOrders(): int
    {
        $orders = $this->db->select(
            "SELECT o.id
               FROM orders o
              WHERE o.status IN (?, ?, ?, ?)
                AND o.paid_at < now() - make_interval(secs => ?)
                AND NOT EXISTS (
                    SELECT 1 FROM jobs j
                     WHERE j.status IN ('pending', 'running')
                       AND j.payload->>'order_id' = o.id
                )",
            [
                Order::PAID, Order::DELIVERING, Order::OUT_OF_STOCK, Order::DELIVERY_FAILED,
                self::STUCK_AFTER_SECONDS,
            ]
        );

        foreach ($orders as $row) {
            $orderId = (string) $row['id'];
            $this->queue->push('deliver_order', ['order_id' => $orderId]);

            $this->logger->info('heal_requeued_order', [
                'channel'  => 'delivery',
                'order_id' => $orderId,
            ]);
        }

        return count($orders);
    }
}
