<?php

declare(strict_types=1);

namespace App\Database;

use Closure;
use PDO;
use PDOException;
use Throwable;

final class PostgresConnection implements Connection
{
    private ?PDO $pdo = null;

    /** Глубина вложенности: COMMIT выполняется на нуле. */
    private int $depth = 0;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
    ) {
    }

    /** Ленивое подключение. Экземпляр разделяется в пределах процесса. */
    public function pdo(): PDO
    {
        return $this->pdo ??= new PDO($this->dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Prepared statements на стороне сервера
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetch() ?: null;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    public function raw(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    public function transaction(Closure $callback): mixed
    {
        $pdo = $this->pdo();

        if ($this->depth === 0) {
            $pdo->beginTransaction();
        }
        $this->depth++;

        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            $this->depth--;
            if ($this->depth === 0 && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->depth--;
        if ($this->depth === 0) {
            $pdo->commit();
        }

        return $result;
    }

    /**
     * Нарушение уникальности, SQLSTATE 23505.
     * Штатный результат конкурентной вставки, не сбой.
     */
    public static function isUniqueViolation(Throwable $e): bool
    {
        return $e instanceof PDOException && ($e->errorInfo[0] ?? null) === '23505';
    }
}
