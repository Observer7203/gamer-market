<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Восстановление состояния на прошлый момент.
 *
 * Текущее состояние заказа лежит в orders и order_items и перезаписывается
 * при каждом изменении: по нему нельзя ответить, что было вчера в полдень.
 * Ответ даёт журнал событий, который только дополняется.
 *
 * Деньги восстанавливаются иначе и проще: журнал проводок изначально ведётся
 * дополнением, и баланс на момент — это сумма движений до этого момента.
 * Отдельной истории для него не требуется.
 *
 * Момент задаётся включительно: событие, случившееся ровно в указанное время,
 * в состояние входит. Иначе граница периода принадлежала бы обоим периодам
 * сразу либо ни одному.
 */
final class TemporalState
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Состояние заказа на момент.
     *
     * @return array<string, mixed>|null null, если на тот момент заказа ещё не было
     */
    public function orderAt(string $orderId, string $at): ?array
    {
        $order = $this->db->selectOne(
            'SELECT to_status, amount_minor, occurred_at
               FROM order_events
              WHERE order_id = ? AND position IS NULL AND occurred_at <= ?
              ORDER BY occurred_at DESC, id DESC
              LIMIT 1',
            [$orderId, $at]
        );

        if ($order === null) {
            return null;
        }

        return [
            'order_id'     => $orderId,
            'at'           => $at,
            'status'       => (string) $order['to_status'],
            'amount_minor' => (int) $order['amount_minor'],
            'changed_at'   => (string) $order['occurred_at'],
            'items'        => $this->itemsAt($orderId, $at),
            'money'        => $this->moneyAt($at, $orderId),
        ];
    }

    /**
     * Состояние позиций на момент.
     *
     * DISTINCT ON отдаёт по одной строке на позицию — последнее событие
     * до указанного времени. Это и есть состояние позиции на тот момент.
     *
     * @return list<array<string, mixed>>
     */
    private function itemsAt(string $orderId, string $at): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT ON (e.position)
                    e.position, e.to_status, e.amount_minor, e.occurred_at,
                    c.code
               FROM order_events e
               LEFT JOIN issued_codes c
                      ON c.order_id = e.order_id AND c.position = e.position
                     AND c.accepted_at <= ?
              WHERE e.order_id = ? AND e.position IS NOT NULL AND e.occurred_at <= ?
              ORDER BY e.position, e.occurred_at DESC, e.id DESC',
            [$at, $orderId, $at]
        );

        return array_map(
            static fn (array $row): array => [
                'position'     => (int) $row['position'],
                'status'       => (string) $row['to_status'],
                'amount_minor' => (int) $row['amount_minor'],
                'changed_at'   => (string) $row['occurred_at'],
                // Код показывается только если он был принят к тому моменту:
                // выданное позже вчерашним числом не показывается.
                'code'         => $row['code'] === null ? null : (string) $row['code'],
            ],
            $rows
        );
    }

    /**
     * Деньги на момент: сумма движений до указанного времени.
     *
     * @return array<string, mixed>
     */
    public function moneyAt(string $at, ?string $orderId = null): array
    {
        $rows = $this->db->select(
            'SELECT account, sum(amount_minor) AS total
               FROM ledger_entries
              WHERE created_at <= ? AND (?::text IS NULL OR order_id = ?)
              GROUP BY account',
            [$at, $orderId, $orderId]
        );

        $balances = [];
        foreach ($rows as $row) {
            $balances[(string) $row['account']] = (int) $row['total'];
        }

        return $this->identity($balances) + ['at' => $at];
    }

    /**
     * Итоги за период.
     *
     * Считаются из той же истории: остаток на конец обязан равняться остатку
     * на начало плюс движения за период. Если равенство не выполняется,
     * значит в журнал вмешались задним числом — а вмешаться в него нельзя.
     *
     * @return array<string, mixed>
     */
    public function periodTotals(string $from, string $to): array
    {
        $opening = $this->accountSums('created_at <= ?', [$from]);
        $closing = $this->accountSums('created_at <= ?', [$to]);
        $movement = $this->accountSums('created_at > ? AND created_at <= ?', [$from, $to]);

        $accounts = array_unique(array_merge(
            array_keys($opening),
            array_keys($closing),
            array_keys($movement),
        ));

        $consistent = true;
        foreach ($accounts as $account) {
            $expected = ($opening[$account] ?? 0) + ($movement[$account] ?? 0);

            if ($expected !== ($closing[$account] ?? 0)) {
                $consistent = false;
            }
        }

        $orders = $this->db->selectOne(
            "SELECT count(*) FILTER (WHERE position IS NULL AND to_status = 'created')   AS created,
                    count(*) FILTER (WHERE position IS NULL AND to_status = 'paid')      AS paid,
                    count(*) FILTER (WHERE position IS NOT NULL AND to_status = 'delivered') AS items_delivered,
                    count(*) FILTER (WHERE position IS NOT NULL AND to_status = 'refunded')  AS items_refunded
               FROM order_events
              WHERE occurred_at > ? AND occurred_at <= ?",
            [$from, $to]
        );

        return [
            'from'     => $from,
            'to'       => $to,
            'orders'   => array_map(intval(...), $orders ?? []),
            'opening'  => $this->identity($opening),
            'movement' => $this->identity($movement),
            'closing'  => $this->identity($closing),

            // Движения за период сами по себе сбалансированы: каждая проводка
            // состоит из строк, дающих в сумме ноль, и период режет проводки
            // целиком — обе строки записаны одной транзакцией.
            'movement_balanced' => array_sum($movement) === 0,
            'consistent'        => $consistent,
        ];
    }

    /**
     * Лента событий заказа.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(string $orderId, ?string $until = null): array
    {
        return $this->db->select(
            'SELECT position, from_status, to_status, amount_minor, occurred_at
               FROM order_events
              WHERE order_id = ? AND (?::timestamptz IS NULL OR occurred_at <= ?)
              ORDER BY occurred_at, id',
            [$orderId, $until, $until]
        );
    }

    /**
     * Заказы и позиции, состояние которых разошлось с историей.
     *
     * В исправной системе список пуст: последнее записанное событие совпадает
     * с тем, что лежит в таблицах. Непустой список означает изменение в обход
     * журнала.
     *
     * Сравнение идёт по порядку записи, а не по времени события: вопрос здесь
     * «что мы записали последним», и отвечает на него возрастающий id.
     * Время события отвечает на другой вопрос — «что было в такой-то момент»,
     * и на нём построено восстановление состояния.
     *
     * @return list<array<string, mixed>>
     */
    public function mismatches(): array
    {
        return $this->db->select(
            "SELECT o.id AS order_id, NULL::integer AS position,
                    o.status AS текущее, last.to_status AS по_истории
               FROM orders o
               LEFT JOIN LATERAL (
                    SELECT to_status FROM order_events e
                     WHERE e.order_id = o.id AND e.position IS NULL
                     ORDER BY e.id DESC LIMIT 1
               ) last ON true
              WHERE last.to_status IS DISTINCT FROM o.status

              UNION ALL

             SELECT i.order_id, i.position, i.status, last.to_status
               FROM order_items i
               LEFT JOIN LATERAL (
                    SELECT to_status FROM order_events e
                     WHERE e.order_id = i.order_id AND e.position = i.position
                     ORDER BY e.id DESC LIMIT 1
               ) last ON true
              WHERE last.to_status IS DISTINCT FROM i.status"
        );
    }

    /**
     * @param list<mixed> $bindings
     * @return array<string, int>
     */
    private function accountSums(string $where, array $bindings): array
    {
        $rows = $this->db->select(
            "SELECT account, sum(amount_minor) AS total
               FROM ledger_entries WHERE $where GROUP BY account",
            $bindings
        );

        $sums = [];
        foreach ($rows as $row) {
            $sums[(string) $row['account']] = (int) $row['total'];
        }

        return $sums;
    }

    /**
     * Тождество денег в привычном виде.
     *
     * @param array<string, int> $balances
     * @return array<string, mixed>
     */
    private function identity(array $balances): array
    {
        $paid       = $balances[Ledger::SETTLEMENT] ?? 0;
        $delivered  = -($balances[Ledger::REVENUE] ?? 0);
        $refunded   = -($balances[Ledger::REFUND] ?? 0);
        $inProgress = -($balances[Ledger::OBLIGATION] ?? 0);

        return [
            'paid_minor'        => $paid,
            'delivered_minor'   => $delivered,
            'refunded_minor'    => $refunded,
            'in_progress_minor' => $inProgress,
            'balanced'          => $paid === $delivered + $refunded + $inProgress,
        ];
    }
}
