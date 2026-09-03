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
    /**
     * @return array{outcome: string, code: ?string, reason: ?string, http: int, latency_ms: int}
     *         outcome: ok | error | timeout
     */
    public function issue(string $requestId, string $sku, string $orderId): array;
}
