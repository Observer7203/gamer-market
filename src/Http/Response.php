<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string, mixed>|null $body */
    public function __construct(
        public readonly int $status,
        public readonly ?array $body = null,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function json(array $body, int $status = 200): self
    {
        return new self($status, $body);
    }

    /** Единый формат ошибки: машиночитаемый код и сообщение. */
    public static function error(string $code, string $message, int $status = 400): self
    {
        return new self($status, ['error' => ['code' => $code, 'message' => $message]]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');

        if ($this->body !== null) {
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
    }
}
