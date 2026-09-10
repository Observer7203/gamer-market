<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\HealStuckOrders;
use App\Services\ProviderStub;
use App\Services\Reconciliation;
use Tests\TestCase;

/**
 * Сверка и фоновая доводка заказов.
 */
final class ReconciliationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function report(): array
    {
        return $this->container->get(Reconciliation::class)->report();
    }

    /** @return array<string, int> */
    private function heal(): array
    {
        return ($this->container->get(HealStuckOrders::class))();
    }

    private function deliverPaidOrder(): string
    {
        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
        $this->runWorker();

        return $order['order_id'];
    }

    public function testИсправнаяСистемаНеИмеетРасхождений(): void
    {
        $this->deliverPaidOrder();

        $report = $this->report();

        self::assertTrue($report['healthy']);
        self::assertSame(0, $report['problems']);
        self::assertSame(200, $this->request('GET', '/api/admin/reconciliation')->status);
    }

    public function testОплаченНоНеВыдан(): void
    {
        $this->container->get(ProviderStub::class)->configure('a', ProviderStub::ERROR, 0.0, 0.0, 1);
        $this->container->get(ProviderStub::class)->configure('b', ProviderStub::ERROR, 0.0, 0.0, 1);

        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
        $this->runWorker();

        // Заказ считается зависшим не сразу: сверка учитывает время ожидания.
        $this->db->execute(
            "UPDATE orders SET paid_at = now() - interval '10 minutes' WHERE id = ?",
            [$order['order_id']]
        );

        $report = $this->report();

        self::assertFalse($report['healthy']);
        self::assertSame(1, $report['summary']['paid_not_delivered']);
        self::assertSame($order['order_id'], $report['paid_not_delivered'][0]['order_id']);
        self::assertSame(409, $this->request('GET', '/api/admin/reconciliation')->status);
    }

    public function testБалансОбязательствСовпадаетСРасхождением(): void
    {
        $this->container->get(ProviderStub::class)->configure('a', ProviderStub::ERROR, 0.0, 0.0, 1);
        $this->container->get(ProviderStub::class)->configure('b', ProviderStub::ERROR, 0.0, 0.0, 1);

        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
        $this->runWorker();

        // Журнал сам показывает размер неисполненного обязательства.
        self::assertSame(-199000, $this->report()['ledger']['balances']['obligation']);
    }

    public function testБрошеннаяЗадачаВозвращаетсяВОчередь(): void
    {
        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));

        // Исполнитель остановлен посреди обработки.
        $this->db->execute("UPDATE jobs SET status = 'running', locked_at = now() - interval '10 minutes'");

        self::assertSame(1, $this->report()['summary']['stuck_jobs']);

        self::assertSame(1, $this->heal()['released_jobs']);
        self::assertSame(0, $this->report()['summary']['stuck_jobs']);
        self::assertSame('pending', $this->db->selectOne('SELECT status FROM jobs LIMIT 1')['status']);
    }

    public function testПотеряннаяЗадачаСоздаётсяЗаново(): void
    {
        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));

        $this->db->execute('DELETE FROM jobs');
        $this->db->execute(
            "UPDATE orders SET paid_at = now() - interval '10 minutes' WHERE id = ?",
            [$order['order_id']]
        );

        self::assertSame(1, $this->heal()['requeued_items']);
        self::assertSame(1, $this->rows('jobs'));

        $this->runWorker();
        self::assertSame('delivered', $this->orderStatus($order['order_id']));
    }

    public function testСобытиеПришедшееРаньшеЗаказаПрименяется(): void
    {
        $futureId = 'ord_TESTFUTURE0000000000000';

        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($futureId));
        self::assertSame(1, $this->report()['summary']['unapplied_events']);

        $this->db->transaction(function () use ($futureId): void {
            $this->db->execute(
                'INSERT INTO orders (id, price_minor, currency, status) VALUES (?, ?, ?, ?)',
                [$futureId, 199000, 'RUB', 'created']
            );
            $this->db->execute(
                'INSERT INTO order_items (order_id, position, sku, price_minor, currency, status)
                      VALUES (?, ?, ?, ?, ?, ?)',
                [$futureId, 1, 'KEY-GTA5', 199000, 'RUB', 'pending']
            );
        });

        self::assertSame(1, $this->heal()['applied_events']);
        self::assertSame('paid', $this->orderStatus($futureId));
        self::assertSame(0, $this->report()['summary']['unapplied_events']);
    }

    public function testПовторнаяДоводкаНичегоНеМеняет(): void
    {
        $orderId = $this->deliverPaidOrder();

        $first = $this->heal();
        $second = $this->heal();

        self::assertSame([0, 0, 0, 0, 0, 0, 0], array_values($first));
        self::assertSame([0, 0, 0, 0, 0, 0, 0], array_values($second));
        self::assertSame(1, $this->rows('deliveries'));
        self::assertSame(4, $this->rows('ledger_entries'));
        self::assertTrue($this->report()['healthy']);
    }

    public function testВыдачаБезОтветаПопадаетВОтчёт(): void
    {
        $this->container->get(ProviderStub::class)
            ->configure('a', ProviderStub::BLACKOUT, 0.0, 0.0, 1);

        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
        $this->runWorker();

        $report = $this->report();

        self::assertSame(1, $report['summary']['unresolved_deliveries']);
        self::assertSame('a', $report['unresolved_deliveries'][0]['unresolved_provider']);
    }
}
