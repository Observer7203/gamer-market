<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Logger;

/**
 * Обращение к поставщику по HTTP.
 *
 * Таймауты заданы явно: без ограничения зависший поставщик удерживал бы
 * исполнителя неопределённо долго.
 *
 * Отсутствие ответа отражается исходом unknown, а не error. Различие
 * принципиально: из error допустимо переключение на резервного поставщика,
 * из unknown — нет, поскольку код мог быть выдан.
 */
final class HttpProviderClient implements ProviderClient
{
    /** @param array<string, string> $endpoints */
    public function __construct(
        private readonly array $endpoints,
        private readonly float $connectTimeout,
        private readonly float $timeout,
        private readonly Logger $logger,
    ) {
    }

    public function issue(string $provider, string $requestId, string $sku, string $orderId): array
    {
        $body = json_encode(
            ['request_id' => $requestId, 'sku' => $sku, 'order_id' => $orderId],
            JSON_UNESCAPED_UNICODE
        );

        $ch = curl_init(($this->endpoints[$provider] ?? '') . '/issue');
        curl_setopt_array($ch, [
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $body,
            CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS        => (int) ($this->timeout * 1000),
        ]);

        $startedAt = microtime(true);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $result = match (true) {
            // Ответ не получен: состояние на стороне поставщика неизвестно.
            $errno === CURLE_OPERATION_TIMEDOUT => [
                'outcome' => self::UNKNOWN, 'code' => null, 'reason' => 'timeout',
            ],
            // Соединение не установлено: запрос до поставщика не дошёл,
            // выдачи произойти не могло.
            $errno !== 0 => [
                'outcome' => self::ERROR, 'code' => null, 'reason' => 'connection_failed',
            ],
            default => $this->parse((string) $raw, $http),
        };

        $result['http'] = $http;
        $result['latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        $this->logger->info('provider_request', [
            'provider'   => $provider,
            'request_id' => $requestId,
            'order_id'   => $orderId,
            'sku'        => $sku,
            'outcome'    => $result['outcome'],
            'reason'     => $result['reason'],
            'http'       => $http,
            'latency_ms' => $result['latency_ms'],
        ]);

        return $result;
    }

    /** @return array{outcome: string, code: ?string, reason: ?string} */
    private function parse(string $raw, int $http): array
    {
        $decoded = json_decode($raw, true);

        if ($http === 200 && is_array($decoded) && ($decoded['status'] ?? null) === 'ok') {
            return ['outcome' => self::OK, 'code' => (string) $decoded['code'], 'reason' => null];
        }

        return [
            'outcome' => self::ERROR,
            'code'    => null,
            'reason'  => is_array($decoded) ? (string) ($decoded['reason'] ?? 'unknown') : 'bad_response',
        ];
    }
}
