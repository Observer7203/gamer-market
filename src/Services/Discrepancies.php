<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Support\Logger;

/**
 * Журнал расхождений с поставщиком.
 *
 * Расхождение — не ошибка приложения, а обнаруженная недобросовестность:
 * поставщик прислал чужой код, подменил код по тому же запросу либо ответил
 * отказом, выдав код на самом деле.
 *
 * Разбор идёт автоматически, но факт должен оставаться видимым: без журнала
 * система молча исправлялась бы, и понять, кому из поставщиков нельзя верить,
 * было бы не по чему.
 */
final class Discrepancies
{
    /** Код уже принадлежит другой позиции. */
    public const DUPLICATE_CODE = 'duplicate_code';

    /** По тому же запросу поставщик прислал другой код. */
    public const REQUEST_CONFLICT = 'request_conflict';

    /** Поставщик ответил отказом или промолчал, но код выдал. */
    public const SILENT_ISSUE = 'silent_issue';

    public function __construct(
        private readonly Connection $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Запись факта расхождения.
     *
     * Повторное обнаружение того же факта не плодит строк: сверка идёт
     * по расписанию и видит одно и то же, пока не разберёт.
     */
    public function record(
        string $kind,
        string $provider,
        string $requestId,
        ?string $orderId = null,
        ?int $position = null,
        ?string $code = null,
        ?string $details = null,
    ): void {
        $this->db->execute(
            'INSERT INTO provider_discrepancies
                    (kind, provider, request_id, order_id, position, code, details)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT DO NOTHING',
            [$kind, $provider, $requestId, $orderId, $position, $code, $details]
        );

        $this->logger->error('provider_discrepancy', [
            'channel'    => 'delivery',
            'kind'       => $kind,
            'provider'   => $provider,
            'request_id' => $requestId,
            'order_id'   => $orderId,
            'position'   => $position,
            'details'    => $details,
        ]);
    }

    /** Расхождение разобрано: отмечается, чем именно оно закончилось. */
    public function resolve(string $kind, string $provider, string $requestId, string $resolution): int
    {
        $resolved = $this->db->execute(
            'UPDATE provider_discrepancies
                SET resolved_at = now(), resolution = ?
              WHERE kind = ? AND provider = ? AND request_id = ? AND resolved_at IS NULL',
            [$resolution, $kind, $provider, $requestId]
        );

        if ($resolved > 0) {
            $this->logger->info('discrepancy_resolved', [
                'channel'    => 'delivery',
                'kind'       => $kind,
                'provider'   => $provider,
                'request_id' => $requestId,
                'resolution' => $resolution,
            ]);
        }

        return $resolved;
    }

    /** @return list<array<string, mixed>> неразобранные расхождения */
    public function open(): array
    {
        return $this->db->select(
            'SELECT kind, provider, request_id, order_id, position, code, details, detected_at
               FROM provider_discrepancies
              WHERE resolved_at IS NULL
              ORDER BY detected_at'
        );
    }

    /** @return array<string, int> сколько расхождений какого рода обнаружено */
    public function summary(): array
    {
        $rows = $this->db->select(
            'SELECT kind,
                    count(*)                                    AS total,
                    count(*) FILTER (WHERE resolved_at IS NULL) AS open
               FROM provider_discrepancies
              GROUP BY kind ORDER BY kind'
        );

        $summary = [];
        foreach ($rows as $row) {
            $summary[(string) $row['kind']] = ['всего' => (int) $row['total'], 'открыто' => (int) $row['open']];
        }

        return $summary;
    }
}
