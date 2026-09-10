<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Queue\Queue;
use App\Support\Logger;

/**
 * Сверка с поставщиками и автоматический разбор расхождений.
 *
 * Ответ поставщика — заявление, состояние на его стороне — факт. Между ними
 * бывает разница: отказ при выданном коде, молчание при выданном коде,
 * выданный дважды код. Разбирать это вручную нельзя — расхождения возникают
 * в фоне и в любом количестве.
 *
 * Сверка идёт от неразрешённых запросов: по каждому спрашивается состояние
 * поставщика, и если код выдан, он принимается и позиция доводится до выдачи.
 *
 * Все действия идемпотентны: приёмка защищена ограничениями таблицы,
 * повторный запуск ничего не удваивает.
 */
final class AuditProviderCodes
{
    public function __construct(
        private readonly Connection $db,
        private readonly ProviderClient $provider,
        private readonly AcceptProviderCode $acceptCode,
        private readonly Discrepancies $discrepancies,
        private readonly Queue $queue,
        private readonly Logger $logger,
    ) {
    }

    /** @return array<string, int> что и сколько было разобрано */
    public function __invoke(): array
    {
        $result = [
            'checked'   => 0,
            'recovered' => 0,
            'resolved'  => 0,
        ];

        foreach ($this->pendingRequests() as $row) {
            $result['checked']++;

            $orderId = (string) $row['order_id'];
            $position = (int) $row['position'];
            $provider = (string) $row['provider'];
            $requestId = (string) $row['request_id'];

            $status = $this->provider->status($provider, $requestId);

            if ($status['outcome'] !== ProviderClient::OK || $status['code'] === null) {
                continue;
            }

            $accepted = ($this->acceptCode)(
                $provider,
                $requestId,
                $orderId,
                $position,
                (string) $status['code'],
            );

            if ($accepted['outcome'] === AcceptProviderCode::DUPLICATE) {
                // Поставщик считает выданным код, который принадлежит другому
                // заказу. Принять нельзя, расхождение уже записано приёмкой,
                // позиция уйдёт на новое поколение запроса.
                continue;
            }

            $this->discrepancies->record(
                Discrepancies::SILENT_ISSUE,
                $provider,
                $requestId,
                $orderId,
                $position,
                $accepted['code'],
                'обнаружено сверкой: код выдан, ответа не было',
            );
            $this->discrepancies->resolve(
                Discrepancies::SILENT_ISSUE,
                $provider,
                $requestId,
                'код принят по состоянию поставщика',
            );

            $this->requeue($orderId, $position);
            $result['recovered']++;
            $result['resolved']++;

            $this->logger->info('audit_recovered_code', [
                'channel'    => 'delivery',
                'order_id'   => $orderId,
                'position'   => $position,
                'provider'   => $provider,
                'request_id' => $requestId,
            ]);
        }

        $result['resolved'] += $this->closeSettledDiscrepancies();

        if ($result['checked'] > 0) {
            $this->logger->info('audit_completed', ['channel' => 'delivery'] + $result);
        }

        return $result;
    }

    /**
     * Запросы, состояние которых у поставщика неизвестно нам.
     *
     * Берутся незавершённые позиции, по которым уже было обращение: только
     * там и возможно расхождение. Завершённые не проверяются — код принят,
     * спрашивать нечего.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingRequests(): array
    {
        return $this->db->select(
            "SELECT DISTINCT a.order_id, a.position, a.provider, a.request_id
               FROM delivery_attempts a
               JOIN order_items i ON i.order_id = a.order_id AND i.position = a.position
              WHERE i.settled_at IS NULL
                AND a.outcome IN ('unknown', 'error')
                AND NOT EXISTS (
                    SELECT 1 FROM issued_codes c
                     WHERE c.provider = a.provider AND c.request_id = a.request_id
                )
              ORDER BY a.order_id, a.position"
        );
    }

    /**
     * Закрытие расхождений по позициям, которые уже завершены.
     *
     * Расхождение могло разрешиться обычным путём: позиция выдана другим
     * поставщиком или деньги возвращены. Держать его открытым незачем.
     */
    private function closeSettledDiscrepancies(): int
    {
        return $this->db->execute(
            "UPDATE provider_discrepancies d
                SET resolved_at = now(),
                    resolution = 'позиция завершена: ' || i.status
               FROM order_items i
              WHERE i.order_id = d.order_id AND i.position = d.position
                AND d.resolved_at IS NULL
                AND i.settled_at IS NOT NULL"
        );
    }

    /** Возврат позиции в очередь, если её там ещё нет. */
    private function requeue(string $orderId, int $position): void
    {
        $exists = $this->db->selectOne(
            "SELECT 1 FROM jobs
              WHERE status IN ('pending', 'running')
                AND payload->>'order_id' = ?
                AND (payload->>'position')::int = ?
              LIMIT 1",
            [$orderId, $position]
        );

        if ($exists === null) {
            $this->queue->push(
                'deliver_item',
                ['order_id' => $orderId, 'position' => $position],
                priority: Queue::PAID_DELIVERY,
            );
        }
    }
}
