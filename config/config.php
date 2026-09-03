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

    'provider' => [
        // Порядок задаёт приоритет: b используется как резервный.
        'endpoints' => [
            'a' => getenv('PROVIDER_A_URL') ?: 'http://nginx/stubs/provider-a',
            'b' => getenv('PROVIDER_B_URL') ?: 'http://nginx/stubs/provider-b',
        ],

        // Таймауты обращения. Неответ в этих пределах не считается отказом.
        'connect_timeout' => (float) (getenv('PROVIDER_CONNECT_TIMEOUT') ?: 1),
        'timeout'         => (float) (getenv('PROVIDER_TIMEOUT') ?: 2),

        // Быстрые повторы внутри одной попытки выдачи: лечат сетевые сбои.
        'max_attempts' => (int) (getenv('PROVIDER_MAX_ATTEMPTS') ?: 3),
        'backoff_ms'   => (int) (getenv('PROVIDER_BACKOFF_MS') ?: 200),
    ],

    'log' => [
        'path' => dirname(__DIR__) . '/storage/logs/app.log',
    ],
];
