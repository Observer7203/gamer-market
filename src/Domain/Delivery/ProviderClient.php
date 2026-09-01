<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use App\Support\Logger;

/**
 * Клиент поставщика выдачи.
 *
 * Таймаут задан явно: без ограничения зависший поставщик удерживал бы
 * воркер неопределённо долго.
 */
final class ProviderClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly float $connectTimeout,
        private readonly float $timeout,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{outcome: string, code: ?string, reason: ?string, http: int, latency_ms: int}
     *         outcome: ok | error | timeout
     */
    public function issue(string $requestId, string $sku, string $orderId): array
    {
        $body = json_encode(
            ['request_id' => $requestId, 'sku' => $sku, 'order_id' => $orderId],
            JSON_UNESCAPED_UNICODE
        );

        $ch = curl_init($this->baseUrl . '/issue');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS        => (int) ($this->timeout * 1000),
        ]);

        $startedAt = microtime(true);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $latency = (int) round((microtime(true) - $startedAt) * 1000);

        $result = match (true) {
            $errno === CURLE_OPERATION_TIMEDOUT => [
                'outcome' => 'timeout', 'code' => null, 'reason' => 'timeout',
            ],
            $errno !== 0 => [
                'outcome' => 'error', 'code' => null, 'reason' => 'connection_failed',
            ],
            default => $this->parse((string) $raw, $http),
        };

        $result['http'] = $http;
        $result['latency_ms'] = $latency;

        $this->logger->info('provider_request', [
            'request_id' => $requestId,
            'order_id'   => $orderId,
            'sku'        => $sku,
            'outcome'    => $result['outcome'],
            'reason'     => $result['reason'],
            'http'       => $http,
            'latency_ms' => $latency,
        ]);

        return $result;
    }

    /** @return array{outcome: string, code: ?string, reason: ?string} */
    private function parse(string $raw, int $http): array
    {
        $decoded = json_decode($raw, true);

        if ($http === 200 && is_array($decoded) && ($decoded['status'] ?? null) === 'ok') {
            return ['outcome' => 'ok', 'code' => (string) $decoded['code'], 'reason' => null];
        }

        return [
            'outcome' => 'error',
            'code'    => null,
            'reason'  => is_array($decoded) ? (string) ($decoded['reason'] ?? 'unknown') : 'bad_response',
        ];
    }
}
