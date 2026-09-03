<?php

declare(strict_types=1);

use App\Database\Migrator;
use App\Support\Container;

/**
 * Подготовка окружения тестов: создание отдельной базы и применение миграций.
 * Выполняется один раз на прогон.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dsn = getenv('DB_DSN');
$user = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

preg_match('/dbname=([^;]+)/', (string) $dsn, $m);
$database = $m[1];

$maintenance = new PDO(
    str_replace('dbname=' . $database, 'dbname=postgres', (string) $dsn),
    (string) $user,
    (string) $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$exists = $maintenance
    ->query("SELECT 1 FROM pg_database WHERE datname = " . $maintenance->quote($database))
    ->fetchColumn();

if ($exists === false) {
    $maintenance->exec('CREATE DATABASE "' . $database . '"');
}

/** @var Container $container */
$container = require dirname(__DIR__) . '/src/bootstrap.php';
$container->get(Migrator::class)->run();
