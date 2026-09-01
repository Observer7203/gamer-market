<?php

declare(strict_types=1);

namespace App\Queue;

use App\Domain\Delivery\DeliverOrder;
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

    public function __construct(
        private readonly Queue $queue,
        private readonly DeliverOrder $deliverOrder,
        private readonly Logger $logger,
    ) {
    }

    public function run(): void
    {
        $this->logger->info('worker_started', ['pid' => getmypid()]);

        while (true) {
            if (!$this->tick()) {
                usleep(self::IDLE_SLEEP_US);
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
            $this->logger->error('job_exhausted', ['job_id' => $id, 'attempts' => $attempts]);

            return;
        }

        $delay = 60 * (2 ** ($attempts - 1));
        $this->queue->retry($id, $error, $delay);

        $this->logger->info('job_rescheduled', [
            'job_id'   => $id,
            'attempts' => $attempts,
            'delay_s'  => $delay,
            'error'    => $error,
        ]);
    }
}
