<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string, mixed> $json
     * @param array<string, string> $query
     * @param array<string, string> $attributes параметры пути: /orders/{id}
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $json = [],
        public readonly array $query = [],
        public array $attributes = [],
        public readonly string $raw = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $raw = (string) file_get_contents('php://input');
        $decoded = $raw === '' ? [] : json_decode($raw, true);

        return new self(
            method: $_SERVER['REQUEST_METHOD'] ?? 'GET',
            path: rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/',
            json: is_array($decoded) ? $decoded : [],
            query: $_GET,
            raw: $raw,
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json[$key] ?? $this->query[$key] ?? $default;
    }

    public function attribute(string $key): string
    {
        return $this->attributes[$key] ?? '';
    }
}
