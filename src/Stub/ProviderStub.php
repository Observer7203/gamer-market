<?php

declare(strict_types=1);

namespace App\Stub;

use App\Database\Connection;

/**
 * Заглушка поставщика выдачи.
 *
 * Ключевое требование контракта: повтор с тем же request_id возвращает тот же
 * код. Обеспечивается уникальным индексом на паре (provider, request_id),
 * а не проверкой в коде — при конкурентных повторах проверка не гарантирует
 * ничего.
 */
final class ProviderStub
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{status: string, code?: string, reason?: string} */
    public function issue(string $provider, string $requestId, string $sku): array
    {
        return $this->db->transaction(function (Connection $db) use ($provider, $requestId, $sku): array {
            $existing = $db->selectOne(
                'SELECT code FROM provider_stock WHERE provider = ? AND request_id = ?',
                [$provider, $requestId]
            );

            if ($existing !== null) {
                return ['status' => 'ok', 'code' => (string) $existing['code']];
            }

            // Захват свободного кода одним оператором: SKIP LOCKED пропускает
            // строки, занятые параллельными запросами, RETURNING отдаёт код
            // без повторного чтения.
            $claimed = $db->selectOne(
                'UPDATE provider_stock
                    SET request_id = ?, issued_at = now()
                  WHERE id = (
                        SELECT id FROM provider_stock
                         WHERE provider = ? AND sku = ? AND request_id IS NULL
                         ORDER BY id
                         LIMIT 1
                         FOR UPDATE SKIP LOCKED
                  )
              RETURNING code',
                [$requestId, $provider, $sku]
            );

            if ($claimed === null) {
                return ['status' => 'error', 'reason' => 'out_of_stock'];
            }

            return ['status' => 'ok', 'code' => (string) $claimed['code']];
        });
    }
}
