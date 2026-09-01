<?php

declare(strict_types=1);

namespace App\Database;

use Closure;

/**
 * Контракт доступа к БД. Домен зависит от него, а не от PDO.
 */
interface Connection
{
    /** @param array<string|int, mixed> $bindings */
    public function select(string $sql, array $bindings = []): array;

    /** @param array<string|int, mixed> $bindings */
    public function selectOne(string $sql, array $bindings = []): ?array;

    /**
     * Возвращает число затронутых строк. Ноль после
     * INSERT ... ON CONFLICT DO NOTHING означает дубликат.
     *
     * @param array<string|int, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int;

    /** Сырой SQL без подготовки: миграции с несколькими операторами в файле. */
    public function raw(string $sql): void;

    /**
     * Вложенные вызовы участвуют во внешней транзакции, COMMIT выполняется один раз.
     *
     * @template T
     * @param Closure(Connection): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed;
}
