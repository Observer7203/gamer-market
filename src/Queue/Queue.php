<?php

declare(strict_types=1);

namespace App\Queue;

use App\Database\Connection;

/**
 * Очередь фоновых задач на таблице в основной базе.
 *
 * Расположение в той же базе позволяет создавать задачу одной транзакцией
 * с изменением данных: либо записаны оба изменения, либо ни одного.
 * С внешним брокером общей транзакции нет.
 */
final class Queue
{
    /** Приоритет оплаченной выдачи: обязательство перед покупателем. */
    public const PAID_DELIVERY = 100;

    /** Приоритет работы по заказу без подтверждённой оплаты. */
    public const UNPAID_DELIVERY = 10;

    /** Приоритет фоновой работы: уступает всему, что ждёт покупатель. */
    public const BACKGROUND = 0;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string, mixed> $payload */
    public function push(
        string $type,
        array $payload,
        int $delaySeconds = 0,
        int $priority = self::BACKGROUND
    ): void {
        $this->db->execute(
            'INSERT INTO jobs (type, payload, run_at, priority)
                  VALUES (?, ?, now() + (? || \' seconds\')::interval, ?)',
            [$type, json_encode($payload, JSON_UNESCAPED_UNICODE), $delaySeconds, $priority]
        );
    }

    /**
     * Захват задачи одним оператором.
     *
     * SKIP LOCKED пропускает строки, заблокированные другими воркерами,
     * вместо ожидания: несколько процессов работают параллельно и никогда
     * не берут одну задачу дважды.
     *
     * Порядок выборки — приоритет, затем срок запуска. Под всплеском очередь
     * длиннее пропускной способности поставщика, и порядок перестаёт быть
     * безразличным: оплаченный заказ ждать не должен.
     *
     * @return array<string, mixed>|null
     */
    public function claim(): ?array
    {
        return $this->db->selectOne(
            'UPDATE jobs
                SET status = \'running\', locked_at = now(), attempts = attempts + 1
              WHERE id = (
                    SELECT id FROM jobs
                     WHERE status = \'pending\' AND run_at <= now()
                     ORDER BY priority DESC, run_at, id
                     LIMIT 1
                     FOR UPDATE SKIP LOCKED
              )
          RETURNING *'
        );
    }

    public function done(int $id): void
    {
        $this->db->execute('UPDATE jobs SET status = \'done\', last_error = NULL WHERE id = ?', [$id]);
    }

    /**
     * Откладывание задачи, к которой не приступали.
     *
     * Отличается от retry тем, что попытка не засчитывается: работа не
     * выполнялась и не проваливалась, ей просто не нашлось места в лимите
     * поставщика. Иначе всплеск исчерпал бы бюджет попыток и задачи,
     * которые ничем не больны, оказались бы проваленными.
     */
    public function defer(int $id, int $delaySeconds, string $reason): void
    {
        $this->db->execute(
            'UPDATE jobs
                SET status = \'pending\', locked_at = NULL, last_error = ?,
                    attempts = greatest(0, attempts - 1),
                    run_at = now() + (? || \' seconds\')::interval
              WHERE id = ?',
            [$reason, $delaySeconds, $id]
        );
    }

    /** Возврат в очередь с отложенным запуском. */
    public function retry(int $id, string $error, int $delaySeconds): void
    {
        $this->db->execute(
            'UPDATE jobs
                SET status = \'pending\', locked_at = NULL, last_error = ?,
                    run_at = now() + (? || \' seconds\')::interval
              WHERE id = ?',
            [$error, $delaySeconds, $id]
        );
    }

    public function fail(int $id, string $error): void
    {
        $this->db->execute('UPDATE jobs SET status = \'failed\', last_error = ? WHERE id = ?', [$error, $id]);
    }
}
