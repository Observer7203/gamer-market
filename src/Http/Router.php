<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Маршрутизация по регулярным выражениям с плейсхолдерами вида {id}.
 * Обработчик задаётся парой [класс, метод] и разрешается контейнером.
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, keys: list<string>, handler: array{0: class-string, 1: string}}> */
    private array $routes = [];

    /** @param array{0: class-string, 1: string} $handler */
    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    private function add(string $method, string $path, array $handler): void
    {
        $keys = [];
        $pattern = preg_replace_callback(
            '/\{([a-z_]+)\}/i',
            function (array $m) use (&$keys): string {
                $keys[] = $m[1];
                return '([^/]+)';
            },
            $path
        );

        $this->routes[] = [
            'method'  => $method,
            'pattern' => '#^' . $pattern . '$#',
            'keys'    => $keys,
            'handler' => $handler,
        ];
    }

    /**
     * @return array{handler: array{0: class-string, 1: string}, attributes: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            array_shift($matches);

            return [
                'handler'    => $route['handler'],
                'attributes' => array_combine($route['keys'], $matches),
            ];
        }

        return null;
    }
}
