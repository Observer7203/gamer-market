<?php

declare(strict_types=1);

namespace App\Database;

/**
 * Применяет .sql из каталога по порядку имён, отмечая выполненные в таблице
 * migrations. Каждый файл — в транзакции: DDL в PostgreSQL транзакционный,
 * прерванная миграция откатывается целиком.
 */
final class Migrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $path,
    ) {
    }

    /** @return list<string> имена применённых миграций */
    public function run(): array
    {
        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS migrations (
                name        text PRIMARY KEY,
                applied_at  timestamptz NOT NULL DEFAULT now()
            )'
        );

        $applied = array_column($this->connection->select('SELECT name FROM migrations'), 'name');

        $files = glob($this->path . '/*.sql') ?: [];
        sort($files);

        $ran = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = (string) file_get_contents($file);

            $this->connection->transaction(function (Connection $db) use ($sql, $name): void {
                $db->raw($sql);
                $db->execute('INSERT INTO migrations (name) VALUES (?)', [$name]);
            });

            $ran[] = $name;
        }

        return $ran;
    }
}
