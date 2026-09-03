<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use Tests\TestCase;

final class CreateOrderTest extends TestCase
{
    public function testСозданиеЗаказаПоАртикулу(): void
    {
        $response = $this->request('POST', '/api/orders', ['sku' => 'KEY-GTA5']);

        self::assertSame(201, $response->status);
        self::assertSame('KEY-GTA5', $response->body['sku']);
        self::assertSame(Order::CREATED, $response->body['status']);
        self::assertStringStartsWith('ord_', $response->body['order_id']);
    }

    public function testСуммуОпределяетСервер(): void
    {
        // Клиент присылает свою цену — она игнорируется
        $response = $this->request('POST', '/api/orders', ['sku' => 'KEY-GTA5', 'amount' => 1]);

        self::assertSame(1990, $response->body['amount']);
        self::assertSame('RUB', $response->body['currency']);
    }

    public function testЦенаФиксируетсяВМоментСоздания(): void
    {
        $order = $this->createOrder();

        $this->db->execute('UPDATE products SET price_minor = 500000 WHERE sku = ?', ['KEY-GTA5']);

        // Переоценка товара не меняет сумму оформленного заказа
        $response = $this->request('GET', '/api/orders/' . $order['order_id']);
        self::assertSame(1990, $response->body['amount']);

        // А новый заказ создаётся уже по новой цене
        self::assertSame(5000, $this->createOrder()['amount']);
    }

    public function testНеизвестныйАртикул(): void
    {
        $response = $this->request('POST', '/api/orders', ['sku' => 'NO-SUCH-SKU']);

        self::assertSame(404, $response->status);
        self::assertSame('product_not_found', $response->body['error']['code']);
        self::assertSame(0, $this->rows('orders'));
    }

    public function testСнятыйСПродажиТоварНедоступен(): void
    {
        $this->db->execute('UPDATE products SET is_active = false WHERE sku = ?', ['KEY-GTA5']);

        self::assertSame(404, $this->request('POST', '/api/orders', ['sku' => 'KEY-GTA5'])->status);
    }

    public function testОтсутствующийАртикулВЗапросе(): void
    {
        $response = $this->request('POST', '/api/orders', []);

        self::assertSame(400, $response->status);
        self::assertSame('invalid_request', $response->body['error']['code']);
    }

    public function testЧтениеЗаказа(): void
    {
        $created = $this->createOrder();
        $response = $this->request('GET', '/api/orders/' . $created['order_id']);

        self::assertSame(200, $response->status);
        self::assertSame($created['order_id'], $response->body['order_id']);
        self::assertArrayNotHasKey('delivery', $response->body, 'код до выдачи не показывается');
    }

    public function testЧтениеНесуществующегоЗаказа(): void
    {
        $response = $this->request('GET', '/api/orders/ord_NETAKOGO');

        self::assertSame(404, $response->status);
        self::assertSame('order_not_found', $response->body['error']['code']);
    }
}
