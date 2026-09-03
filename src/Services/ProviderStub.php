<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Заглушка поставщика выдачи.
 *
 * Ключевое требование контракта: повтор с тем же request_id возвращает тот же
 * код. Обеспечивается уникальным индексом на паре (provider, request_id),
 * а не проверкой в коде — при конкурентных повторах проверка не гарантирует
 * ничего.
 *
 * Поведение задаётся таблицей provider_settings. Режим random соответствует
 * условию «случайно падает и отвечает с задержкой»; остальные режимы делают
 * поведение детерминированным, что требуется для воспроизводимых сценариев.
 */
final class ProviderStub
{
    public const OK                 = 'ok';
    public const ERROR              = 'error';
    public const OUT_OF_STOCK       = 'out_of_stock';
    public const TIMEOUT            = 'timeout';
    public const ISSUE_THEN_TIMEOUT = 'issue_then_timeout';
    public const RANDOM             = 'random';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Флаг hang отключает задержку: в тестах ожидание реального таймаута
     * удлинило бы прогон на секунды, не добавив проверяемого поведения.
     * Признак того, что ответ не дошёл бы, возвращается полем hung.
     *
     * @return array{status: string, code?: string, reason?: string, hung?: bool}
     */
    public function issue(string $provider, string $requestId, string $sku, bool $hang = true): array
    {
        $settings = $this->settings($provider);
        $mode = $this->resolveMode($settings);

        // Отказ до обращения к складу: код не выдаётся.
        if ($mode === self::ERROR) {
            return ['status' => 'error', 'reason' => 'provider_unavailable'];
        }

        // Молчание до обращения к складу: код не выдан, но вызывающая сторона
        // об этом не знает.
        if ($mode === self::TIMEOUT) {
            if ($hang) {
                $this->hang((int) $settings['hang_seconds']);
            }

            return ['status' => 'error', 'reason' => 'timeout', 'hung' => true];
        }

        if ($mode === self::OUT_OF_STOCK) {
            return ['status' => 'error', 'reason' => 'out_of_stock'];
        }

        $result = $this->claimCode($provider, $requestId, $sku);

        // Код выдан, ответ не доходит. Ровно та ситуация, ради которой
        // неответ нельзя трактовать как отказ: повтор с тем же request_id
        // обязан вернуть уже выданный код, а не занять второй.
        if ($mode === self::ISSUE_THEN_TIMEOUT) {
            if ($hang) {
                $this->hang((int) $settings['hang_seconds']);
            }

            $result['hung'] = true;
        }

        return $result;
    }

    /** @return array{status: string, code?: string, reason?: string} */
    private function claimCode(string $provider, string $requestId, string $sku): array
    {
        return $this->db->transaction(function (Connection $db) use ($provider, $requestId, $sku): array {
            $existing = $db->selectOne(
                'SELECT code FROM provider_stock WHERE provider = ? AND request_id = ?',
                [$provider, $requestId]
            );

            if ($existing !== null) {
                return ['status' => 'ok', 'code' => (string) $existing['code']];
            }

            // Захват свободного кода одним оператором: SKIP LOCKED пропускает
            // строки, занятые параллельными запросами, RETURNING отдаёт код
            // без повторного чтения.
            $claimed = $db->selectOne(
                'UPDATE provider_stock
                    SET request_id = ?, issued_at = now()
                  WHERE id = (
                        SELECT id FROM provider_stock
                         WHERE provider = ? AND sku = ? AND request_id IS NULL
                         ORDER BY id
                         LIMIT 1
                         FOR UPDATE SKIP LOCKED
                  )
              RETURNING code',
                [$requestId, $provider, $sku]
            );

            if ($claimed === null) {
                return ['status' => 'error', 'reason' => 'out_of_stock'];
            }

            return ['status' => 'ok', 'code' => (string) $claimed['code']];
        });
    }

    /** @return array<string, mixed> */
    private function settings(string $provider): array
    {
        return $this->db->selectOne(
            'SELECT * FROM provider_settings WHERE provider = ?',
            [$provider]
        ) ?? ['mode' => self::OK, 'fail_rate' => 0.0, 'timeout_rate' => 0.0, 'hang_seconds' => 5];
    }

    /**
     * Разыгрывает поведение для режима random, иначе возвращает заданное.
     *
     * @param array<string, mixed> $settings
     */
    private function resolveMode(array $settings): string
    {
        if ($settings['mode'] !== self::RANDOM) {
            return (string) $settings['mode'];
        }

        $roll = mt_rand(0, 9999) / 10000;
        $failRate = (float) $settings['fail_rate'];
        $timeoutRate = (float) $settings['timeout_rate'];

        if ($roll < $failRate) {
            return self::ERROR;
        }

        if ($roll < $failRate + $timeoutRate) {
            // Половина неответов приходится на случай, когда код уже выдан:
            // именно он отличает неответ от отказа.
            return mt_rand(0, 1) === 1 ? self::ISSUE_THEN_TIMEOUT : self::TIMEOUT;
        }

        return self::OK;
    }

    /** Задержка, превышающая таймаут вызывающей стороны. */
    private function hang(int $seconds): void
    {
        sleep(max(1, $seconds));
    }

    /** Установка поведения. Используется сценариями проверки. */
    public function configure(string $provider, string $mode, float $failRate, float $timeoutRate, int $hangSeconds): void
    {
        $this->db->execute(
            'INSERT INTO provider_settings (provider, mode, fail_rate, timeout_rate, hang_seconds)
                  VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (provider) DO UPDATE
                     SET mode = excluded.mode,
                         fail_rate = excluded.fail_rate,
                         timeout_rate = excluded.timeout_rate,
                         hang_seconds = excluded.hang_seconds,
                         updated_at = now()',
            [$provider, $mode, $failRate, $timeoutRate, $hangSeconds]
        );
    }
}
