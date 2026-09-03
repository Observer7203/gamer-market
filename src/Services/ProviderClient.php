<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Контракт обращения к поставщику выдачи.
 *
 * Отделён от реализации, потому что вызов уходит за пределы процесса:
 * в тестах он подменяется локальным исполнением, иначе заглушка отвечала бы
 * из другого процесса с другим подключением к базе.
 */
interface ProviderClient
{
    public const OK      = 'ok';
    public const ERROR   = 'error';
    public const UNKNOWN = 'unknown';

    /**
     * Исход unknown означает отсутствие ответа. Он не равнозначен отказу:
     * поставщик мог выдать код, а ответ не дойти. Различение этих случаев —
     * условие того, что повтор не приведёт к повторной выдаче.
     *
     * @return array{outcome: string, code: ?string, reason: ?string, http: int, latency_ms: int}
     */
    public function issue(string $provider, string $requestId, string $sku, string $orderId): array;
}
