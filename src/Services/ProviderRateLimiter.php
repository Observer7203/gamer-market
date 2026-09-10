<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Support\Logger;

/**
 * Ограничитель частоты обращений к поставщику.
 *
 * Поставщик принимает ограниченное число запросов в минуту. Лимит общий
 * для всех исполнителей, поэтому счётчик не может жить в памяти процесса:
 * десять воркеров с локальным счётчиком дадут десятикратное превышение.
 *
 * Окно скользящее, а не календарная минута. Токенное ведро с запасом,
 * равным лимиту, допускает всплеск: лимит за минуту с 0-й по 60-ю секунду
 * соблюдён, с 59-й по 119-ю — превышен вдвое. Скользящее окно отвечает
 * на вопрос «сколько обращений было за последние шестьдесят секунд»,
 * и превысить лимит не даёт ни в какой момент.
 *
 * Порядок сериализуется блокировкой строки лимита: два исполнителя не могут
 * одновременно увидеть свободное место и оба его занять. Транзакция здесь
 * своя и короткая — блокировка не удерживается на время обращения
 * к поставщику.
 */
final class ProviderRateLimiter
{
    public function __construct(
        private readonly Connection $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Занять место под одно обращение.
     *
     * @return bool false означает «сейчас нельзя», а не отказ: работа
     *              откладывается и повторяется позже
     */
    public function acquire(string $provider): bool
    {
        return $this->db->transaction(function (Connection $db) use ($provider): bool {
            $limit = $db->selectOne(
                'SELECT limit_per_minute, window_seconds FROM provider_rate_limits
                  WHERE provider = ? FOR UPDATE',
                [$provider]
            );

            // Поставщик без заданного лимита не ограничивается: правило
            // задаётся явно, а не подразумевается.
            if ($limit === null) {
                return true;
            }

            $used = $this->used($db, $provider, (int) $limit['window_seconds']);

            if ($used >= (int) $limit['limit_per_minute']) {
                $this->logger->info('provider_throttled', [
                    'channel'  => 'delivery',
                    'provider' => $provider,
                    'used'     => $used,
                    'limit'    => (int) $limit['limit_per_minute'],
                ]);

                return false;
            }

            $db->execute('INSERT INTO provider_calls (provider) VALUES (?)', [$provider]);

            return true;
        });
    }

    /** @return array<string, array<string, int>> состояние лимитов по поставщикам */
    public function state(): array
    {
        $rows = $this->db->select(
            'SELECT provider, limit_per_minute, window_seconds FROM provider_rate_limits ORDER BY provider'
        );

        $state = [];

        foreach ($rows as $row) {
            $provider = (string) $row['provider'];
            $limit = (int) $row['limit_per_minute'];
            $used = $this->used($this->db, $provider, (int) $row['window_seconds']);

            $state[$provider] = [
                'limit_per_minute' => $limit,
                'used'             => $used,
                'available'        => max(0, $limit - $used),
            ];
        }

        return $state;
    }

    /**
     * Наибольшее число обращений в любом окне прошлого.
     *
     * Проверка того, что лимит не превышался: окно двигается по каждому
     * обращению, потому что именно в эти моменты счёт и может вырасти.
     *
     * @return array<string, int>
     */
    public function peak(): array
    {
        $rows = $this->db->select(
            'SELECT c.provider,
                    max(w.calls) AS peak
               FROM provider_calls c
               JOIN provider_rate_limits l ON l.provider = c.provider
               JOIN LATERAL (
                    SELECT count(*) AS calls
                      FROM provider_calls x
                     WHERE x.provider = c.provider
                       AND x.called_at > c.called_at - make_interval(secs => l.window_seconds)
                       AND x.called_at <= c.called_at
               ) w ON true
              GROUP BY c.provider
              ORDER BY c.provider'
        );

        $peak = [];
        foreach ($rows as $row) {
            $peak[(string) $row['provider']] = (int) $row['peak'];
        }

        return $peak;
    }

    /** Задать лимит. Используется сценариями проверки. */
    public function configure(string $provider, int $limitPerMinute, int $windowSeconds = 60): void
    {
        $this->db->execute(
            'INSERT INTO provider_rate_limits (provider, limit_per_minute, window_seconds)
                  VALUES (?, ?, ?)
             ON CONFLICT (provider) DO UPDATE
                     SET limit_per_minute = excluded.limit_per_minute,
                         window_seconds = excluded.window_seconds,
                         updated_at = now()',
            [$provider, $limitPerMinute, $windowSeconds]
        );
    }

    /**
     * Удаление обращений, вышедших за окно.
     *
     * Журнал нужен только для окна и для проверки. Без очистки он рос бы
     * вместе с числом обращений, а отвечал бы всё на тот же вопрос.
     */
    public function prune(int $keepSeconds = 3600): int
    {
        return $this->db->execute(
            'DELETE FROM provider_calls WHERE called_at < now() - make_interval(secs => ?)',
            [$keepSeconds]
        );
    }

    private function used(Connection $db, string $provider, int $windowSeconds): int
    {
        return (int) $db->selectOne(
            'SELECT count(*) AS n FROM provider_calls
              WHERE provider = ? AND called_at > now() - make_interval(secs => ?)',
            [$provider, $windowSeconds]
        )['n'];
    }
}
