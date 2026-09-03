<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\ProviderClient;
use App\Services\ProviderStub;

/**
 * Обращение к заглушке поставщика внутри процесса.
 *
 * По HTTP запрос ушёл бы в другой процесс с другим подключением к базе,
 * и заглушка работала бы со складом рабочей базы вместо тестовой.
 */
final class LocalProviderClient implements ProviderClient
{
    public function __construct(private readonly ProviderStub $stub)
    {
    }

    public function issue(string $requestId, string $sku, string $orderId): array
    {
        $result = $this->stub->issue('a', $requestId, $sku);

        return [
            'outcome'    => $result['status'] === 'ok' ? 'ok' : 'error',
            'code'       => $result['code'] ?? null,
            'reason'     => $result['reason'] ?? null,
            'http'       => $result['status'] === 'ok' ? 200 : 409,
            'latency_ms' => 0,
        ];
    }
}
