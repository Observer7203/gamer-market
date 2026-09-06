<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Витрина каталога.
 *
 * Самый частый запрос системы: каталог читают на порядок чаще, чем создают
 * заказы. Поэтому он устроен так, чтобы выполняться сканированием только
 * по индексу, без обращения к самой таблице.
 *
 * Остаток берётся из денормализованной колонки, а не считается подзапросом:
 * коррелированный подзапрос выполняется для каждой строки-кандидата
 * и на тысячах позиций перестаёт укладываться в отведённое время.
 *
 * Постраничный вывод — по ключу, а не смещением. Смещение заставляет
 * прочитать и отбросить все предыдущие строки, поэтому глубокие страницы
 * дорожают линейно.
 */
final class Catalog
{
    private const PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{type?: ?string, in_stock?: bool, after?: ?string, limit?: ?int} $options
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function showcase(array $options = []): array
    {
        $limit = min(max(1, (int) ($options['limit'] ?? self::PAGE_SIZE)), self::MAX_PAGE_SIZE);
        $type = $options['type'] ?? null;
        $inStock = (bool) ($options['in_stock'] ?? false);

        $conditions = ['is_active'];
        $bindings = [];

        if ($type !== null && $type !== '') {
            $conditions[] = 'type = ?';
            $bindings[] = $type;
        }

        // Позиция в наборе задаётся парой: ранг задаёт порядок, артикул
        // разрешает совпадения рангов.
        [$rank, $sku] = $this->decodeCursor($options['after'] ?? null);

        if ($rank !== null) {
            $conditions[] = '(sort_rank, sku) < (?, ?)';
            $bindings[] = $rank;
            $bindings[] = $sku;
        }

        if ($inStock) {
            $conditions[] = 'available_count > 0';
        }

        $rows = $this->db->select(
            'SELECT sku, name, type, price_minor, currency, available_count, sort_rank
               FROM products
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY sort_rank DESC, sku DESC
              LIMIT ' . ($limit + 1),
            $bindings
        );

        // Лишняя строка запрашивается, чтобы узнать о наличии следующей
        // страницы без отдельного подсчёта.
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'items' => array_map(
                static fn (array $row): array => [
                    'sku'       => $row['sku'],
                    'name'      => $row['name'],
                    'type'      => $row['type'],
                    'price'     => (int) $row['price_minor'] / 100,
                    'currency'  => $row['currency'],
                    'available' => (int) $row['available_count'],
                ],
                $rows
            ),
            'next_cursor' => $hasMore && $rows !== []
                ? $this->encodeCursor((int) end($rows)['sort_rank'], (string) end($rows)['sku'])
                : null,
        ];
    }

    private function encodeCursor(int $rank, string $sku): string
    {
        return rtrim(strtr(base64_encode($rank . ':' . $sku), '+/', '-_'), '=');
    }

    /** @return array{0: ?int, 1: ?string} */
    private function decodeCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return [null, null];
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            return [null, null];
        }

        [$rank, $sku] = explode(':', $decoded, 2);

        return [(int) $rank, $sku];
    }
}
