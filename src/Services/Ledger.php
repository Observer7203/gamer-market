<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Журнал денежных движений.
 *
 * Двойная запись: каждая проводка состоит из строк, сумма которых равна нулю.
 * Баланс счёта не хранится, а вычисляется суммой движений — иначе он стал бы
 * вторым источником правды и мог разойтись с журналом.
 *
 * Тождество, которое обязано выполняться всегда:
 *
 *     оплачено = выдано + возвращено + ещё в работе
 *
 * В журнале оно выражено тем, что сумма всех счетов равна нулю, а остаток
 * по счёту обязательств равен стоимости незавершённых позиций.
 */
final class Ledger
{
    /** Средства, поступившие от платёжной системы. */
    public const SETTLEMENT = 'settlement';

    /** Обязательство выдать оплаченный товар либо вернуть деньги. */
    public const OBLIGATION = 'obligation';

    /** Признанная выручка: обязательство исполнено выдачей. */
    public const REVENUE = 'revenue';

    /** Возвращено покупателю: обязательство исполнено возвратом. */
    public const REFUND = 'refund';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Поступление оплаты: средства пришли, возникло обязательство по заказу.
     *
     * Идентификатор проводки выводится из события платёжной системы, поэтому
     * повторная запись того же факта невозможна — за этим следит уникальный
     * индекс на паре (txn_id, account).
     */
    public function recordPayment(string $orderId, string $eventId, int $amountMinor, string $currency): void
    {
        $this->post('pay_' . $eventId, $orderId, $currency, 'payment_received', [
            self::SETTLEMENT => +$amountMinor,
            self::OBLIGATION => -$amountMinor,
        ]);
    }

    /** Выдача позиции: часть обязательства исполнена, выручка признана. */
    public function recordDelivery(string $orderId, int $position, int $amountMinor, string $currency): void
    {
        $this->post(
            sprintf('deliver_%s_%d', $orderId, $position),
            $orderId,
            $currency,
            'item_delivered',
            [self::OBLIGATION => +$amountMinor, self::REVENUE => -$amountMinor]
        );
    }

    /** Возврат за невыданную позицию: обязательство исполнено деньгами. */
    public function recordRefund(string $orderId, int $position, int $amountMinor, string $currency): void
    {
        $this->post(
            sprintf('refund_%s_%d', $orderId, $position),
            $orderId,
            $currency,
            'item_refunded',
            [self::OBLIGATION => +$amountMinor, self::REFUND => -$amountMinor]
        );
    }

    /** @return array<string, int> баланс по счетам в минорных единицах */
    public function balances(): array
    {
        $rows = $this->db->select(
            'SELECT account, sum(amount_minor) AS total FROM ledger_entries GROUP BY account ORDER BY account'
        );

        $balances = [];
        foreach ($rows as $row) {
            $balances[(string) $row['account']] = (int) $row['total'];
        }

        return $balances;
    }

    /** @return array<string, int> движение денег по одному заказу */
    public function balancesFor(string $orderId): array
    {
        $rows = $this->db->select(
            'SELECT account, sum(amount_minor) AS total FROM ledger_entries
              WHERE order_id = ? GROUP BY account ORDER BY account',
            [$orderId]
        );

        $balances = [];
        foreach ($rows as $row) {
            $balances[(string) $row['account']] = (int) $row['total'];
        }

        return $balances;
    }

    /**
     * Проводки, сумма которых отлична от нуля.
     *
     * В нормальной работе список пуст: отложенный триггер не позволяет
     * зафиксировать несбалансированную проводку. Запрос существует ради
     * отчёта сверки, а не потому, что такое ожидается.
     *
     * @return list<array<string, mixed>>
     */
    public function imbalanced(): array
    {
        return $this->db->select(
            'SELECT txn_id, order_id, sum(amount_minor) AS imbalance
               FROM ledger_entries GROUP BY txn_id, order_id HAVING sum(amount_minor) <> 0'
        );
    }

    /**
     * Завершённые заказы, по которым оплаченное не равно выданному плюс
     * возвращённому. В исправной системе список пуст.
     *
     * @return list<array<string, mixed>>
     */
    public function unsettledOrders(): array
    {
        return $this->db->select(
            "SELECT l.order_id,
                    sum(l.amount_minor) FILTER (WHERE l.account = 'settlement') AS paid,
                    -sum(l.amount_minor) FILTER (WHERE l.account = 'revenue')   AS delivered,
                    -sum(l.amount_minor) FILTER (WHERE l.account = 'refund')    AS refunded,
                    sum(l.amount_minor) FILTER (WHERE l.account = 'obligation') AS outstanding
               FROM ledger_entries l
               JOIN orders o ON o.id = l.order_id
              WHERE o.status IN ('delivered', 'partially_delivered', 'refunded')
              GROUP BY l.order_id
             HAVING sum(l.amount_minor) FILTER (WHERE l.account = 'obligation') <> 0"
        );
    }

    /**
     * @param array<string, int> $entries счёт => сумма со знаком
     */
    private function post(string $txnId, string $orderId, string $currency, string $reason, array $entries): void
    {
        foreach ($entries as $account => $amount) {
            $this->db->execute(
                'INSERT INTO ledger_entries (txn_id, order_id, account, amount_minor, currency, reason)
                      VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (txn_id, account) DO NOTHING',
                [$txnId, $orderId, $account, $amount, $currency, $reason]
            );
        }
    }
}
