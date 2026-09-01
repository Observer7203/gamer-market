<?php

declare(strict_types=1);

/**
 * Единственная точка чтения окружения.
 */
return [
    'db' => [
        'dsn'      => getenv('DB_DSN') ?: 'pgsql:host=db;port=5432;dbname=gamer_market',
        'user'     => getenv('DB_USER') ?: 'gamer',
        'password' => getenv('DB_PASSWORD') ?: 'secret',
    ],

    'log' => [
        'path' => dirname(__DIR__) . '/storage/logs/app.log',
    ],
];
