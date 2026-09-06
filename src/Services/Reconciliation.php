<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Сверка состояния системы.
 *
 * Отвечает на два вопроса, ответ на которые в исправной системе пуст:
 * «оплачен, но не выдан» и «выдан, но не оплачен». Первый означает
 * неисполненное обязательство перед покупателем, второй — отданный
 * без оплаты товар.
 *
 * Остальные разделы показывают состояния, из которых система не вышла
 * самостоятельно: зависшие задачи, неразрешённые обращения к поставщику,
 * непринятые платёжные события.
 */
final class Reconciliation
{
    /** Заказ считается зависшим, если не завершился за это время. */
    private const STUCK_AFTER_SECONDS = 300;

    public function __construct(
        private readonly Connection $db,
        private readonly Ledger $ledger,
    ) {
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $paidNotDelivered = $this->paidNotDelivered();
        $deliveredNotPaid = $this->deliveredNotPaid();
        $stuckJobs        = $this->stuckJobs();
        $unresolved       = $this->unresolvedDeliveries();
        $unappliedEvents  = $this->unappliedEvents();
        $imbalanced       = $this->ledger->imbalanced();

        $problems = count($paidNotDelivered) + count($deliveredNotPaid)
            + count($stuckJobs) + count($unresolved)
            + count($unappliedEvents) + count($imbalanced);

        return [
            'generated_at' => gmdate('c'),
            'healthy'      => $problems === 0,
            'problems'     => $problems,

            'summary' => [
                'orders'            => $this->count('orders'),
                'delivered'         => $this->count('orders', "status = 'delivered'"),
                'paid_not_delivered' => count($paidNotDelivered),
                'delivered_not_paid' => count($deliveredNotPaid),
                'stuck_jobs'        => count($stuckJobs),
                'unresolved_deliveries' => count($unresolved),
                'unapplied_events'  => count($unappliedEvents),
                'ledger_imbalanced' => count($imbalanced),
            ],

            'ledger' => [
                'balances' => $this->ledger->balances(),
                'note'     => 'obligation — оплачено, но не выдано; сумма всех счетов равна нулю',
            ],

            'paid_not_delivered'     => $paidNotDelivered,
            'delivered_not_paid'     => $deliveredNotPaid,
            'stuck_jobs'             => $stuckJobs,
            'unresolved_deliveries'  => $unresolved,
            'unapplied_events'       => $unappliedEvents,
            'ledger_imbalanced'      => $imbalanced,
        ];
    }

    /**
     * Оплачен, но не выдан.
     *
     * Деньги получены, товар не отдан. Заказы, не завершившиеся в отведённое
     * время, требуют вмешательства или ожидают восстановления поставщика.
     *
     * @return list<array<string, mixed>>
     */
    private function paidNotDelivered(): array
    {
        return $this->db->select(
            "SELECT o.id AS order_id, o.status, o.sku, o.price_minor, o.paid_at,
                    coalesce(d.status, '—') AS delivery_status,
                    coalesce(d.last_error, '') AS last_error,
                    coalesce(d.unresolved_provider, '') AS unresolved_provider,
                    round(extract(epoch FROM now() - o.paid_at))::int AS waiting_seconds
               FROM orders o
               LEFT JOIN deliveries d ON d.order_id = o.id
              WHERE o.paid_at IS NOT NULL
                AND o.status <> 'delivered'
                AND o.paid_at < now() - make_interval(secs => ?)
              ORDER BY o.paid_at",
            [self::STUCK_AFTER_SECONDS]
        );
    }

    /**
     * Выдан, но не оплачен.
     *
     * Товар отдан без подтверждённой оплаты. В исправной системе недостижимо:
     * задача на выдачу создаётся только вместе с переводом заказа в оплаченный.
     *
     * @return list<array<string, mixed>>
     */
    private function deliveredNotPaid(): array
    {
        return $this->db->select(
            "SELECT d.order_id, d.code, d.provider, d.delivered_at, o.status
               FROM deliveries d
               JOIN orders o ON o.id = d.order_id
              WHERE d.status = 'delivered' AND o.paid_at IS NULL
              ORDER BY d.delivered_at"
        );
    }

    /**
     * Задачи, взятые в работу и не завершённые.
     *
     * Исполнитель мог быть остановлен посреди обработки: задача осталась
     * в состоянии выполнения и без вмешательства не будет взята повторно.
     *
     * @return list<array<string, mixed>>
     */
    private function stuckJobs(): array
    {
        return $this->db->select(
            "SELECT id, type, payload->>'order_id' AS order_id, attempts, locked_at,
                    round(extract(epoch FROM now() - locked_at))::int AS locked_seconds
               FROM jobs
              WHERE status = 'running' AND locked_at < now() - make_interval(secs => ?)
              ORDER BY locked_at",
            [self::STUCK_AFTER_SECONDS]
        );
    }

    /**
     * Выдачи, по которым поставщик не ответил.
     *
     * Состояние поставщика неизвестно: код мог быть выдан. Переключение
     * на резервного запрещено, требуется повторное обращение к тому же.
     *
     * @return list<array<string, mixed>>
     */
    private function unresolvedDeliveries(): array
    {
        return $this->db->select(
            "SELECT order_id, unresolved_provider, request_id, attempts, last_error
               FROM deliveries WHERE status = 'unresolved' ORDER BY order_id"
        );
    }

    /**
     * Платёжные события, не применённые к заказу.
     *
     * Обычно это события по заказу, которого ещё нет. Они не теряются
     * и применяются при его появлении.
     *
     * @return list<array<string, mixed>>
     */
    private function unappliedEvents(): array
    {
        return $this->db->select(
            "SELECT event_id, order_id, status, amount_minor, outcome, received_at
               FROM payment_events WHERE processed_at IS NULL ORDER BY received_at"
        );
    }

    private function count(string $table, string $where = 'true'): int
    {
        return (int) $this->db->selectOne("SELECT count(*) AS n FROM $table WHERE $where")['n'];
    }
}
