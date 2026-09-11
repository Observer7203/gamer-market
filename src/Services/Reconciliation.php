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
 * Третий вопрос — сходятся ли деньги. Тождество проверяется по журналу:
 *
 *     оплачено = выдано + возвращено + ещё в работе
 *
 * Четвёртый — совпадает ли текущее состояние с воспроизведённым по истории.
 * Расхождение означает изменение в обход журнала событий.
 *
 * Остальные разделы показывают состояния, из которых система не вышла
 * самостоятельно: зависшие задачи, неразрешённые обращения к поставщику,
 * непринятые платёжные события.
 */
final class Reconciliation
{
    /** Позиция считается зависшей, если не завершилась за это время. */
    private const STUCK_AFTER_SECONDS = 300;

    public function __construct(
        private readonly Connection $db,
        private readonly Ledger $ledger,
        private readonly Discrepancies $discrepancies,
        private readonly TemporalState $history,
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
        $unsettled        = $this->ledger->unsettledOrders();
        $money            = $this->money();
        $openDiscrepancies = $this->discrepancies->open();
        $unacceptedCodes  = $this->unacceptedCodes();
        $historyMismatches = $this->history->mismatches();

        $problems = count($paidNotDelivered) + count($deliveredNotPaid)
            + count($stuckJobs) + count($unresolved)
            + count($unappliedEvents) + count($imbalanced) + count($unsettled)
            + count($openDiscrepancies) + count($unacceptedCodes)
            + count($historyMismatches)
            + ($money['balanced'] ? 0 : 1);

        return [
            'generated_at' => gmdate('c'),
            'healthy'      => $problems === 0,
            'problems'     => $problems,

            'summary' => [
                'orders'                => $this->count('orders'),
                'items'                 => $this->count('order_items'),
                'delivered_items'       => $this->count('order_items', "status = 'delivered'"),
                'refunded_items'        => $this->count('order_items', "status = 'refunded'"),
                'partially_delivered'   => $this->count('orders', "status = 'partially_delivered'"),
                'paid_not_delivered'    => count($paidNotDelivered),
                'delivered_not_paid'    => count($deliveredNotPaid),
                'stuck_jobs'            => count($stuckJobs),
                'unresolved_deliveries' => count($unresolved),
                'unapplied_events'      => count($unappliedEvents),
                'ledger_imbalanced'     => count($imbalanced),
                'unsettled_orders'      => count($unsettled),
                'open_discrepancies'    => count($openDiscrepancies),
                'unaccepted_codes'      => count($unacceptedCodes),
                'history_mismatches'    => count($historyMismatches),
            ],

            'discrepancies' => $this->discrepancies->summary(),

            'money'  => $money,
            'ledger' => ['balances' => $this->ledger->balances()],

            'paid_not_delivered'    => $paidNotDelivered,
            'delivered_not_paid'    => $deliveredNotPaid,
            'stuck_jobs'            => $stuckJobs,
            'unresolved_deliveries' => $unresolved,
            'unapplied_events'      => $unappliedEvents,
            'ledger_imbalanced'     => $imbalanced,
            'unsettled_orders'      => $unsettled,
            'open_discrepancies'    => $openDiscrepancies,
            'unaccepted_codes'      => $unacceptedCodes,
            'history_mismatches'    => $historyMismatches,
        ];
    }

    /**
     * Тождество денег.
     *
     * Каждый принятый рубль обязан находиться ровно в одном из трёх
     * состояний: отработан выдачей, возвращён покупателю либо ещё
     * составляет обязательство по незавершённым позициям.
     *
     * @return array<string, mixed>
     */
    private function money(): array
    {
        $balances = $this->ledger->balances();

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
            'identity'          => 'оплачено = выдано + возвращено + в работе',
        ];
    }

    /**
     * Оплачен, но не выдан.
     *
     * Деньги получены, позиция не отдана и не возвращена. Позиции,
     * не завершившиеся в отведённое время, требуют вмешательства
     * или ожидают восстановления поставщика.
     *
     * @return list<array<string, mixed>>
     */
    private function paidNotDelivered(): array
    {
        return $this->db->select(
            "SELECT i.order_id, i.position, i.sku, i.status, i.price_minor, o.paid_at,
                    coalesce(d.status, '—') AS delivery_status,
                    coalesce(d.last_error, '') AS last_error,
                    coalesce(d.unresolved_provider, '') AS unresolved_provider,
                    round(extract(epoch FROM now() - o.paid_at))::int AS waiting_seconds
               FROM order_items i
               JOIN orders o ON o.id = i.order_id
               LEFT JOIN deliveries d ON d.order_id = i.order_id AND d.position = i.position
              WHERE o.paid_at IS NOT NULL
                AND i.settled_at IS NULL
                AND o.paid_at < now() - make_interval(secs => ?)
              ORDER BY o.paid_at, i.position",
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
            "SELECT d.order_id, d.position, d.code, d.provider, d.delivered_at, o.status
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
            "SELECT id, type, payload->>'order_id' AS order_id, payload->>'position' AS position,
                    attempts, locked_at,
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
     * Состояние поставщика неизвестно: код мог быть выдан. Ни переключение
     * на резервного, ни возврат денег из этого состояния недопустимы —
     * требуется повторное обращение к тому же поставщику.
     *
     * @return list<array<string, mixed>>
     */
    private function unresolvedDeliveries(): array
    {
        return $this->db->select(
            "SELECT order_id, position, unresolved_provider, request_id, attempts, last_error
               FROM deliveries WHERE status = 'unresolved' ORDER BY order_id, position"
        );
    }

    /**
     * Коды, ушедшие покупателю в обход приёмки.
     *
     * В исправной системе список пуст: код попадает в выдачу только после
     * того, как приёмка признала его нашим. Непустой список означает путь,
     * которым ответ поставщика был принят на веру.
     *
     * @return list<array<string, mixed>>
     */
    private function unacceptedCodes(): array
    {
        return $this->db->select(
            "SELECT d.order_id, d.position, d.provider, d.request_id, d.code
               FROM deliveries d
              WHERE d.status = 'delivered' AND d.code IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM issued_codes c
                     WHERE c.code = d.code
                       AND c.order_id = d.order_id AND c.position = d.position
                )
              ORDER BY d.order_id, d.position"
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
