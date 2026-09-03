<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Проверка exactly-once под настоящей конкуренцией.
 *
 * Внутри одного процесса PHP параллельность недостижима, поэтому набор
 * запускает bin/race: он открывает соединения через curl_multi и порождает
 * процессы через fork, а инварианты проверяет запросами к базе.
 *
 * В отличие от остальных наборов требует поднятого окружения и работает
 * с основной базой: заглушка поставщика отвечает из контейнера приложения.
 */
final class RaceTest extends TestCase
{
    private const PARALLEL = 50;
    private const REPEAT = 3;
    private const MAIN_DSN = 'pgsql:host=db;port=5432;dbname=gamer_market';

    protected function setUp(): void
    {
        $probe = @file_get_contents('http://nginx/health');

        if ($probe === false) {
            self::markTestSkipped('Окружение не поднято: недоступен http://nginx');
        }
    }

    #[DataProvider('modes')]
    public function testИнвариантыДержатсяПодКонкуренцией(string $mode, string $description): void
    {
        // Окружение задаётся явно: набор наследует тестовую базу, тогда как
        // заказы создаются через HTTP и попадают в основную.
        $command = sprintf(
            'DB_DSN=%s DB_USER=%s DB_PASSWORD=%s php %s/bin/race'
                . ' --mode=%s --parallel=%d --repeat=%d 2>&1',
            escapeshellarg(self::MAIN_DSN),
            escapeshellarg('gamer'),
            escapeshellarg('secret'),
            dirname(__DIR__, 2),
            $mode,
            self::PARALLEL,
            self::REPEAT
        );

        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, $description . "\n" . implode("\n", $output));
        self::assertStringContainsString('нарушений: 0', implode("\n", $output));
    }

    /** @return array<string, array{string, string}> */
    public static function modes(): array
    {
        return [
            'одинаковый event_id' => [
                'same',
                'Повторная доставка одного события не создаёт вторую выдачу',
            ],
            'разные event_id' => [
                'different',
                'Разные события по одному заказу приводят к единственной выдаче',
            ],
            'конкурирующие исполнители' => [
                'workers',
                'Одновременная выдача несколькими процессами выполняется один раз',
            ],
        ];
    }
}
