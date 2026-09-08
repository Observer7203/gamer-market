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
        self::assertSame('KEY-GTA5', $response->body['items'][0]['sku']);
        self::assertCount(1, $response->body['items']);
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

    public function testЗаказИзНесколькихПозиций(): void
    {
        $this->seedProduct('KEY-CS2', 99000, 3);

        $response = $this->request('POST', '/api/orders', [
            'items' => [['sku' => 'KEY-GTA5'], ['sku' => 'KEY-CS2'], ['sku' => 'KEY-GTA5']],
        ]);

        self::assertSame(201, $response->status);
        self::assertCount(3, $response->body['items']);
        self::assertSame([1, 2, 3], array_column($response->body['items'], 'position'));
        self::assertSame(4970, $response->body['amount'], 'сумма заказа равна сумме позиций');
        self::assertSame(3, $this->rows('order_items'));
    }

    public function testКоличествоРазворачиваетсяВОтдельныеПозиции(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['sku' => 'KEY-GTA5', 'quantity' => 3]],
        ]);

        // У каждой единицы свой код и свой исход, поэтому это три позиции
        self::assertCount(3, $response->body['items']);
        self::assertSame(5970, $response->body['amount']);
    }

    public function testНеизвестныйАртикулОтменяетВесьЗаказ(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['sku' => 'KEY-GTA5'], ['sku' => 'NO-SUCH-SKU']],
        ]);

        self::assertSame(404, $response->status);
        self::assertSame(0, $this->rows('orders'), 'заказ не создан частично');
        self::assertSame(0, $this->rows('order_items'));
    }

    public function testПустойСоставНеПринимается(): void
    {
        $response = $this->request('POST', '/api/orders', ['items' => []]);

        self::assertSame(400, $response->status);
        self::assertSame('invalid_request', $response->body['error']['code']);
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
        self::assertArrayNotHasKey('code', $response->body['items'][0], 'код до выдачи не показывается');
    }

    public function testЧтениеНесуществующегоЗаказа(): void
    {
        $response = $this->request('GET', '/api/orders/ord_NETAKOGO');

        self::assertSame(404, $response->status);
        self::assertSame('order_not_found', $response->body['error']['code']);
    }
}
