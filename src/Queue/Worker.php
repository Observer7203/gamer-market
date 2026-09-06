<?php

declare(strict_types=1);

namespace App\Queue;

use App\Database\Connection;
use App\Services\DeliverOrder;
use App\Support\Logger;
use Throwable;

/**
 * Воркер очереди: отдельный долгоживущий процесс.
 *
 * Выдача вынесена сюда, потому что обращение к поставщику занимает секунды,
 * а ответ платёжной системе должен уложиться в миллисекунды.
 *
 * Неудача не помечает задачу проваленной сразу: она возвращается в очередь
 * с экспоненциальной отсрочкой. Процесс при этом не ожидает — сдвигается
 * поле run_at, и воркер переходит к следующей задаче.
 */
final class Worker
{
    private const MAX_ATTEMPTS = 8;
    private const IDLE_SLEEP_US = 200_000;

    /** Пауза после сбоя соединения, растёт до минуты. */
    private const RECONNECT_BASE_SECONDS = 1;
    private const RECONNECT_MAX_SECONDS = 60;

    public function __construct(
        private readonly Queue $queue,
        private readonly DeliverOrder $deliverOrder,
        private readonly Connection $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Основной цикл.
     *
     * Недоступность базы не завершает процесс: соединение сбрасывается,
     * пауза растёт, попытки продолжаются. Перезапуск базы или кратковременный
     * разрыв связи не должны требовать перезапуска исполнителя.
     */
    public function run(): void
    {
        $this->logger->info('worker_started', ['channel' => 'queue', 'pid' => getmypid()]);

        $failures = 0;

        while (true) {
            try {
                $worked = $this->tick();
                $failures = 0;

                if (!$worked) {
                    usleep(self::IDLE_SLEEP_US);
                }
            } catch (Throwable $e) {
                $failures++;
                $pause = min(
                    self::RECONNECT_BASE_SECONDS * (2 ** ($failures - 1)),
                    self::RECONNECT_MAX_SECONDS
                );

                $this->logger->error('worker_connection_lost', [
                    'channel'   => 'queue',
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                    'failures'  => $failures,
                    'retry_in'  => $pause,
                ]);

                // Дескриптор после разрыва непригоден: следующий запрос
                // должен открыть новое соединение.
                $this->db->disconnect();
                sleep($pause);
            }
        }
    }

    /** @return bool была ли обработана задача */
    public function tick(): bool
    {
        $job = $this->queue->claim();

        if ($job === null) {
            return false;
        }

        $id = (int) $job['id'];
        $payload = json_decode((string) $job['payload'], true) ?: [];

        try {
            $outcome = match ($job['type']) {
                'deliver_order' => ($this->deliverOrder)((string) $payload['order_id']),
                default         => throw new \RuntimeException("Неизвестный тип задачи [{$job['type']}]"),
            };

            if ($outcome === 'delivered' || $outcome === 'noop') {
                $this->queue->done($id);
            } else {
                $this->reschedule($job, $outcome);
            }
        } catch (Throwable $e) {
            $this->logger->error('job_failed', [
                'channel'   => 'queue',
                'job_id'    => $id,
                'type'      => $job['type'],
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            $this->reschedule($job, $e->getMessage());
        }

        return true;
    }

    /** @param array<string, mixed> $job */
    private function reschedule(array $job, string $error): void
    {
        $id = (int) $job['id'];
        $attempts = (int) $job['attempts'];

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->queue->fail($id, $error);
            $this->logger->error('job_exhausted', ['channel' => 'queue', 'job_id' => $id, 'attempts' => $attempts]);

            return;
        }

        $delay = 60 * (2 ** ($attempts - 1));
        $this->queue->retry($id, $error, $delay);

        $this->logger->info('job_rescheduled', [
            'channel'  => 'queue',
            'job_id'   => $id,
            'attempts' => $attempts,
            'delay_s'  => $delay,
            'error'    => $error,
        ]);
    }
}
