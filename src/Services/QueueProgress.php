<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Прогресс очереди под нагрузкой.
 *
 * Под всплеском очередь длиннее пропускной способности поставщика, и вопрос
 * «когда выдадут» становится законным. Без ответа на него система выглядит
 * зависшей, хотя работает.
 *
 * Оценка времени считается из лимита поставщиков, а не из наблюдаемой
 * скорости: скорость — следствие лимита, и при пустой очереди её просто нет.
 */
final class QueueProgress
{
    public function __construct(
        private readonly Connection $db,
        private readonly ProviderRateLimiter $rateLimiter,
    ) {
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $queue = $this->queue();
        $limits = $this->rateLimiter->state();

        $capacity = 0;
        foreach ($limits as $limit) {
            $capacity += $limit['limit_per_minute'];
        }

        // Отложенные задачи — те же ожидающие: они ждут не своего часа,
        // а места в лимите поставщика.
        $waiting = $queue['pending'] + $queue['deferred'] + $queue['running'];

        return [
            'generated_at' => gmdate('c'),
            'queue'        => $queue,
            'items'        => $this->items(),
            'providers'    => $limits,
            'throughput'   => [
                'limit_per_minute'      => $capacity,
                'delivered_last_minute' => $this->deliveredSince('1 minute'),
                'waiting'               => $waiting,
                // Оценка сверху: считается по лимиту, а не по наблюдаемой
                // скорости. Скорость — следствие лимита, и при пустой очереди
                // её просто нет.
                'eta_seconds' => $capacity === 0 ? null : (int) ceil($waiting / $capacity * 60),
            ],
        ];
    }

    /** @return array<string, int> */
    private function queue(): array
    {
        $row = $this->db->selectOne(
            "SELECT count(*) FILTER (WHERE status = 'pending' AND run_at <= now()) AS pending,
                    count(*) FILTER (WHERE status = 'pending' AND run_at > now())  AS deferred,
                    count(*) FILTER (WHERE status = 'running')                     AS running,
                    count(*) FILTER (WHERE status = 'failed')                      AS failed,
                    count(*) FILTER (WHERE status = 'pending' AND priority >= 100) AS paid_ahead
               FROM jobs"
        );

        return array_map(intval(...), $row ?? []);
    }

    /**
     * Позиции.
     *
     * Ожидающими считаются только позиции оплаченных заказов: покупатель ждёт
     * там, где заплатил. Позиция неоплаченного заказа в очередь не попадает
     * и квоту поставщика не расходует — считать её ожидающей значит завышать
     * и очередь, и оценку времени.
     *
     * @return array<string, int>
     */
    private function items(): array
    {
        $row = $this->db->selectOne(
            "SELECT count(*) FILTER (WHERE i.settled_at IS NULL AND o.paid_at IS NOT NULL) AS awaiting,
                    count(*) FILTER (WHERE i.settled_at IS NULL AND o.paid_at IS NULL)     AS unpaid,
                    count(*) FILTER (WHERE i.status = 'delivered')                         AS delivered,
                    count(*) FILTER (WHERE i.status = 'refunded')                          AS refunded,
                    count(*) FILTER (WHERE i.status = 'unresolved')                        AS unresolved
               FROM order_items i
               JOIN orders o ON o.id = i.order_id"
        );

        return array_map(intval(...), $row ?? []);
    }

    private function deliveredSince(string $interval): int
    {
        return (int) $this->db->selectOne(
            "SELECT count(*) AS n FROM deliveries
              WHERE status = 'delivered' AND delivered_at > now() - interval '$interval'"
        )['n'];
    }
}
