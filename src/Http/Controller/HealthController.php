<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Database\Connection;
use App\Http\Request;
use App\Http\Response;
use Throwable;

/**
 * Проверка готовности: приложение отвечает и располагает соединением с БД.
 */
final class HealthController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function show(Request $request): Response
    {
        try {
            $this->connection->selectOne('SELECT 1 AS ok');
            $database = 'up';
        } catch (Throwable) {
            $database = 'down';
        }

        return Response::json([
            'status'   => $database === 'up' ? 'ok' : 'degraded',
            'database' => $database,
            'php'      => PHP_VERSION,
        ], $database === 'up' ? 200 : 503);
    }
}
