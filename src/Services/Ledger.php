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
 * Проводки записываются в той же транзакции, что и изменение состояния
 * заказа: состояние «оплачен, но в журнале ничего нет» недостижимо.
 */
final class Ledger
{
    /** Средства, поступившие от платёжной системы. */
    public const SETTLEMENT = 'settlement';

    /** Обязательство выдать оплаченный товар. */
    public const OBLIGATION = 'obligation';

    /** Признанная выручка: обязательство исполнено. */
    public const REVENUE = 'revenue';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Поступление оплаты: средства пришли, возникло обязательство выдать товар.
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

    /** Выдача товара: обязательство исполнено, выручка признана. */
    public function recordDelivery(string $orderId, int $amountMinor, string $currency): void
    {
        $this->post('deliver_' . $orderId, $orderId, $currency, 'order_delivered', [
            self::OBLIGATION => +$amountMinor,
            self::REVENUE    => -$amountMinor,
        ]);
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
