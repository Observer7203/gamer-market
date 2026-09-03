<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use App\Services\ProviderStub;
use Tests\TestCase;

/**
 * Устойчивость интеграций: повторы, переключение на резервного поставщика
 * и обработка отсутствия ответа.
 */
final class ProviderFailoverTest extends TestCase
{
    /** @return array<string, mixed> */
    private function deliverPaidOrder(): array
    {
        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
        $this->runWorker();

        return $order;
    }

    private function behavior(string $provider, string $mode): void
    {
        $this->container->get(ProviderStub::class)->configure($provider, $mode, 0.0, 0.0, 1);
    }

    /** @return array<string, mixed> */
    private function delivery(string $orderId): array
    {
        return $this->db->selectOne('SELECT * FROM deliveries WHERE order_id = ?', [$orderId]) ?? [];
    }

    private function codesTaken(string $orderId, ?string $provider = null): int
    {
        $sql = 'SELECT count(*) AS n FROM provider_stock WHERE request_id LIKE ?';
        $args = ['%' . $orderId . '%'];

        if ($provider !== null) {
            $sql .= ' AND provider = ?';
            $args[] = $provider;
        }

        return (int) $this->db->selectOne($sql, $args)['n'];
    }

    public function testОсновнойПоставщикВыдаётКод(): void
    {
        $this->behavior('a', ProviderStub::OK);

        $order = $this->deliverPaidOrder();

        self::assertSame(Order::DELIVERED, $this->orderStatus($order['order_id']));
        self::assertSame('a', $this->delivery($order['order_id'])['provider']);
        self::assertSame(1, $this->codesTaken($order['order_id']));
    }

    public function testОтказОсновногоПереключаетНаРезервного(): void
    {
        $this->behavior('a', ProviderStub::ERROR);
        $this->behavior('b', ProviderStub::OK);

        $order = $this->deliverPaidOrder();
        $delivery = $this->delivery($order['order_id']);

        self::assertSame(Order::DELIVERED, $this->orderStatus($order['order_id']));
        self::assertSame('b', $delivery['provider']);
        self::assertSame(0, $this->codesTaken($order['order_id'], 'a'), 'у отказавшего код не списан');
        self::assertSame(1, $this->codesTaken($order['order_id'], 'b'));
    }

    public function testЯвныйОтказНеПовторяется(): void
    {
        $this->behavior('a', ProviderStub::ERROR);
        $this->behavior('b', ProviderStub::OK);

        $order = $this->deliverPaidOrder();

        // Состояние поставщика известно: повторы к нему бессмысленны.
        $attempts = $this->db->select(
            "SELECT * FROM delivery_attempts WHERE order_id = ? AND provider = 'a'",
            [$order['order_id']]
        );

        self::assertCount(1, $attempts);
        self::assertSame('error', $attempts[0]['outcome']);
    }

    public function testПустойОстатокПереключаетНаРезервного(): void
    {
        $this->behavior('a', ProviderStub::OUT_OF_STOCK);
        $this->behavior('b', ProviderStub::OK);

        $order = $this->deliverPaidOrder();

        self::assertSame(Order::DELIVERED, $this->orderStatus($order['order_id']));
        self::assertSame('b', $this->delivery($order['order_id'])['provider']);
    }

    public function testОтсутствиеОтветаНеПринимаетсяЗаОтказ(): void
    {
        // Поставщик выдал код, ответ не дошёл.
        $this->behavior('a', ProviderStub::ISSUE_THEN_TIMEOUT);
        $this->behavior('b', ProviderStub::OK);

        $order = $this->deliverPaidOrder();
        $delivery = $this->delivery($order['order_id']);

        self::assertSame(Delivery::UNRESOLVED, $delivery['status']);
        self::assertSame('a', $delivery['unresolved_provider']);

        // Переключение на резервного создало бы вторую выдачу.
        self::assertSame(0, $this->codesTaken($order['order_id'], 'b'), 'на резервного не переключились');
        self::assertSame(1, $this->codesTaken($order['order_id'], 'a'), 'основной списал ровно один код');

        $unknown = $this->db->select(
            "SELECT * FROM delivery_attempts WHERE order_id = ? AND outcome = 'unknown'",
            [$order['order_id']]
        );
        self::assertNotEmpty($unknown);
    }

    public function testПовторПослеНеответаВозвращаетТотЖеКод(): void
    {
        $this->behavior('a', ProviderStub::ISSUE_THEN_TIMEOUT);
        $this->behavior('b', ProviderStub::OK);

        $order = $this->deliverPaidOrder();
        $orderId = $order['order_id'];

        $claimed = $this->db->selectOne(
            'SELECT code FROM provider_stock WHERE request_id LIKE ?',
            ['%' . $orderId . '%']
        );

        // Поставщик восстановился, повтор идёт к нему же с тем же request_id.
        $this->behavior('a', ProviderStub::OK);
        $this->db->execute("UPDATE jobs SET run_at = now() WHERE status = 'pending'");
        $this->runWorker();

        $delivery = $this->delivery($orderId);

        self::assertSame(Order::DELIVERED, $this->orderStatus($orderId));
        self::assertSame('a', $delivery['provider']);
        self::assertSame($claimed['code'], $delivery['code'], 'возвращён ранее занятый код');
        self::assertSame(1, $this->codesTaken($orderId), 'второго кода не появилось');
    }

    public function testОтказОбоихВосстановим(): void
    {
        $this->behavior('a', ProviderStub::ERROR);
        $this->behavior('b', ProviderStub::ERROR);

        $order = $this->deliverPaidOrder();
        $orderId = $order['order_id'];

        self::assertSame(Order::DELIVERY_FAILED, $this->orderStatus($orderId));
        self::assertSame(0, $this->codesTaken($orderId));
        self::assertSame(200, $this->request('GET', '/health')->status);

        // Задача возвращена в очередь, а не помечена проваленной.
        $job = $this->db->selectOne('SELECT * FROM jobs ORDER BY id DESC LIMIT 1');
        self::assertSame('pending', $job['status']);

        $this->behavior('b', ProviderStub::OK);
        $this->db->execute("UPDATE jobs SET run_at = now() WHERE status = 'pending'");
        $this->runWorker();

        self::assertSame(Order::DELIVERED, $this->orderStatus($orderId));
        self::assertSame(1, $this->codesTaken($orderId));
    }

    public function testПовторыИдутСТемЖеИдентификаторомЗапроса(): void
    {
        $this->behavior('a', ProviderStub::TIMEOUT);
        $this->behavior('b', ProviderStub::OK);

        $order = $this->deliverPaidOrder();

        $requestIds = array_unique(array_column(
            $this->db->select(
                "SELECT request_id FROM delivery_attempts WHERE order_id = ? AND provider = 'a'",
                [$order['order_id']]
            ),
            'request_id'
        ));

        // Идентификатор детерминирован: повтор обращается к тому же запросу.
        self::assertCount(1, $requestIds);
        self::assertSame('req_' . $order['order_id'] . '_a', $requestIds[0]);
    }
}
