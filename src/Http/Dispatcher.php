<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Container;
use App\Support\Logger;
use Throwable;

/**
 * Диспетчер HTTP-запроса: маршрут, разрешение контроллера через контейнер,
 * вызов, преобразование непойманного исключения в 500.
 *
 * Принимает Request и возвращает Response, поэтому маршрутизация и коды
 * ответов покрываются тестами без запуска веб-сервера.
 */
final class Dispatcher
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly Logger $logger,
    ) {
    }

    public function handle(Request $request): Response
    {
        $route = $this->router->match($request->method, $request->path);

        if ($route === null) {
            return Response::error('not_found', 'Маршрут не найден', 404);
        }

        $request->attributes = $route['attributes'];
        [$class, $method] = $route['handler'];

        try {
            return $this->container->get($class)->{$method}($request);
        } catch (Throwable $e) {
            $this->logger->error('unhandled_exception', [
                'path'      => $request->path,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ]);

            return Response::error('internal_error', 'Внутренняя ошибка', 500);
        }
    }
}
