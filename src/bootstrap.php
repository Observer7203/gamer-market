<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migrator;
use App\Database\PostgresConnection;
use App\Services\HttpProviderClient;
use App\Services\ProviderClient;
use App\Http\Router;
use App\Support\Container;
use App\Support\Logger;

/**
 * Точка сборки объектного графа, общая для HTTP, CLI и фоновых процессов.
 * Связывание интерфейсов с реализациями выполняется только здесь.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

return (static function (): Container {
    $root = dirname(__DIR__);
    $config = require $root . '/config/config.php';

    $container = new Container();

    // Одно соединение на процесс: транзакция должна быть общей
    // для всех потребителей в пределах запроса
    $container->singleton(Connection::class, fn (): Connection => new PostgresConnection(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['password'],
    ));

    $container->singleton(Logger::class, fn (): Logger => new Logger($config['log']['path']));

    $container->singleton(ProviderClient::class, fn (Container $c): ProviderClient => new HttpProviderClient(
        $config['provider']['url'],
        $config['provider']['connect_timeout'],
        $config['provider']['timeout'],
        $c->get(Logger::class),
    ));

    $container->singleton(Migrator::class, fn (Container $c): Migrator => new Migrator(
        $c->get(Connection::class),
        $root . '/migrations',
    ));

    $container->singleton(Router::class, function (): Router {
        $router = new Router();
        (require dirname(__DIR__) . '/config/routes.php')($router);

        return $router;
    });

    return $container;
})();
