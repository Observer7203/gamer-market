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
 *
 * Задержка заглушки заменена немедленным исходом unknown: ожидание реального
 * таймаута удлинило бы прогон на секунды, не добавив проверяемого поведения.
 */
final class LocalProviderClient implements ProviderClient
{
    public function __construct(private readonly ProviderStub $stub)
    {
    }

    public function issue(string $provider, string $requestId, string $sku, string $orderId): array
    {
        $result = $this->stub->issue($provider, $requestId, $sku, hang: false);

        // Признак отсутствия ответа проверяется первым: при режиме
        // «выдал и замолчал» заглушка возвращает и код, и hung, но до
        // вызывающей стороны ответ не дошёл бы.
        $outcome = match (true) {
            ($result['hung'] ?? false) === true => self::UNKNOWN,
            $result['status'] === 'ok'          => self::OK,
            default                             => self::ERROR,
        };

        return [
            'outcome'    => $outcome,
            'code'       => $outcome === self::OK ? $result['code'] : null,
            'reason'     => $result['reason'] ?? null,
            'http'       => $result['status'] === 'ok' ? 200 : 500,
            'latency_ms' => 0,
        ];
    }
}
