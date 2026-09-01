<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Структурированный лог: одна строка — один JSON-объект.
 */
final class Logger
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    /** @param array<string, mixed> $context */
    public function error(string $event, array $context = []): void
    {
        $record = [
            'ts'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP'),
            'level' => 'error',
            'event' => $event,
            'pid'   => getmypid(),
            ...$context,
        ];

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        if ($this->handle === null) {
            $directory = dirname($this->path);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            $this->handle = fopen($this->path, 'ab');
        }

        // Конкурентная запись из php-fpm и фоновых процессов
        if ($this->handle !== false && $this->handle !== null) {
            flock($this->handle, LOCK_EX);
            fwrite($this->handle, $line);
            flock($this->handle, LOCK_UN);
        }
    }
}
