<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use Tests\TestCase;

final class DeliveryTest extends TestCase
{
    public function testСквознойПутьДоВыдачиКода(): void
    {
        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));

        $this->runWorker();

        $response = $this->request('GET', '/api/orders/' . $order['order_id']);

        self::assertSame(Order::DELIVERED, $response->body['status']);
        self::assertMatchesRegularExpression('/^[ab]-TEST-\d{4}$/', $response->body['delivery']['code']);
        self::assertNotEmpty($response->body['delivery']['delivered_at']);
    }

    public function testНаЗаказПриходитсяРовноОднаВыдача(): void
    {
        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
        $this->runWorker();

        // Повторный запуск выдачи по уже выданному заказу
        $outcome = ($this->container->get(\App\Services\DeliverOrder::class))($order['order_id']);

        self::assertSame('noop', $outcome);
        self::assertSame(1, $this->rows('deliveries'));
        self::assertSame(1, $this->rows('provider_stock', 'request_id IS NOT NULL'));
    }

    public function testКодНеУходитВДваЗаказа(): void
    {
        $codes = [];

        for ($i = 0; $i < 3; $i++) {
            $order = $this->createOrder();
            $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
            $this->runWorker();

            $codes[] = $this->request('GET', '/api/orders/' . $order['order_id'])->body['delivery']['code'];
        }

        self::assertCount(3, array_unique($codes), 'каждому заказу достался свой код');
    }

    public function testПустойОстатокВосстановим(): void
    {
        // Резервный поставщик тоже без товара: иначе заказ ушёл бы к нему.
        $this->container->get(\App\Services\ProviderStub::class)
            ->configure('b', \App\Services\ProviderStub::OUT_OF_STOCK, 0.0, 0.0, 1);

        // На складе три кода, четвёртому заказу товара не хватит
        for ($i = 0; $i < 3; $i++) {
            $order = $this->createOrder();
            $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
            $this->runWorker();
        }

        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
        $this->runWorker();

        self::assertSame(Order::OUT_OF_STOCK, $this->orderStatus($order['order_id']));

        // Приложение остаётся работоспособным
        self::assertSame(200, $this->request('GET', '/health')->status);

        // Задача возвращена в очередь, а не помечена проваленной
        $job = $this->db->selectOne('SELECT * FROM jobs ORDER BY id DESC LIMIT 1');
        self::assertSame('pending', $job['status']);
        self::assertSame('out_of_stock', $job['last_error']);

        // После пополнения склада повтор доводит заказ до выдачи
        $this->db->execute(
            'INSERT INTO provider_stock (provider, sku, code) VALUES (?, ?, ?)',
            ['a', 'KEY-GTA5', 'a-TEST-9999']
        );
        $this->db->execute('UPDATE jobs SET run_at = now() WHERE id = ?', [$job['id']]);
        $this->runWorker();

        self::assertSame(Order::DELIVERED, $this->orderStatus($order['order_id']));
        self::assertSame('a-TEST-9999', $this->request('GET', '/api/orders/' . $order['order_id'])
            ->body['delivery']['code']);
    }

    public function testПовторКПоставщикуВозвращаетТотЖеКод(): void
    {
        $first = $this->request('POST', '/stubs/provider-a/issue', [
            'request_id' => 'req_one',
            'sku'        => 'KEY-GTA5',
            'order_id'   => 'ord_one',
        ]);

        $second = $this->request('POST', '/stubs/provider-a/issue', [
            'request_id' => 'req_one',
            'sku'        => 'KEY-GTA5',
            'order_id'   => 'ord_one',
        ]);

        self::assertSame(200, $first->status);
        self::assertSame($first->body['code'], $second->body['code']);
        self::assertSame(1, $this->rows('provider_stock', 'request_id IS NOT NULL'));
    }

    public function testПоставщикСообщаетОбИсчерпанииОстатка(): void
    {
        $this->db->raw('TRUNCATE provider_stock');

        $response = $this->request('POST', '/stubs/provider-a/issue', [
            'request_id' => 'req_empty',
            'sku'        => 'KEY-GTA5',
            'order_id'   => 'ord_empty',
        ]);

        self::assertSame(409, $response->status);
        self::assertSame('out_of_stock', $response->body['reason']);
    }

    public function testСостоянияВыдачиИЗаказаСогласованы(): void
    {
        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
        $this->runWorker();

        $row = $this->db->selectOne('SELECT * FROM deliveries WHERE order_id = ?', [$order['order_id']]);
        $delivery = Delivery::fromRow($row);

        self::assertTrue($delivery->isDelivered());
        self::assertSame('a', $delivery->provider);
        self::assertSame('req_' . $order['order_id'] . '_a', $delivery->requestId);
        self::assertSame(1, $delivery->attempts);
    }
}
