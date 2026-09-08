<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\DeliverOrderItem;
use App\Services\FinalizeOrder;
use App\Services\HealStuckOrders;
use App\Services\Ledger;
use App\Services\RefundItem;
use Tests\TestCase;

/**
 * Заказ, часть позиций которого выдать невозможно.
 *
 * Выданное остаётся у покупателя, за невыданное возвращаются деньги,
 * тождество «оплачено = выдано + возвращено» выполняется, а повтор любого
 * шага ничего не удваивает.
 */
final class PartialOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Товар, которого нет ни у одного поставщика.
        $this->seedProduct('KEY-SOLDOUT', 50000, 0);
    }

    /**
     * Заказ, доведённый до конечного состояния.
     *
     * Позиции без остатка добирают бюджет попыток прямыми вызовами:
     * в очереди они ждали бы нарастающей отсрочки.
     *
     * @param list<string> $skus
     */
    private function settledOrder(array $skus): string
    {
        $order = $this->createOrderWith($skus);
        $orderId = $order['order_id'];

        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent(
            $orderId,
            ['amount' => $order['amount']]
        ));
        $this->runWorker();

        $deliver = $this->container->get(DeliverOrderItem::class);

        foreach (array_keys($skus) as $index) {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $status = $this->itemStatuses($orderId)[$index + 1];

                if (in_array($status, [OrderItem::DELIVERED, OrderItem::REFUNDED], true)) {
                    break;
                }

                $deliver($orderId, $index + 1);
            }
        }

        return $orderId;
    }

    /** @return array<string, int> */
    private function money(string $orderId): array
    {
        $balances = $this->container->get(Ledger::class)->balancesFor($orderId);

        return [
            'paid'      => $balances[Ledger::SETTLEMENT] ?? 0,
            'delivered' => -($balances[Ledger::REVENUE] ?? 0),
            'refunded'  => -($balances[Ledger::REFUND] ?? 0),
            'open'      => -($balances[Ledger::OBLIGATION] ?? 0),
        ];
    }

    public function testВыданноеОстаётсяНевыданноеВозвращается(): void
    {
        $orderId = $this->settledOrder(['KEY-GTA5', 'KEY-SOLDOUT', 'KEY-GTA5']);

        self::assertSame(
            [1 => OrderItem::DELIVERED, 2 => OrderItem::REFUNDED, 3 => OrderItem::DELIVERED],
            $this->itemStatuses($orderId)
        );
        self::assertSame(Order::PARTIALLY_DELIVERED, $this->orderStatus($orderId));

        $body = $this->request('GET', '/api/orders/' . $orderId)->body;

        self::assertArrayHasKey('code', $body['items'][0]);
        self::assertArrayNotHasKey('code', $body['items'][1], 'за возвращённое кода нет');
        self::assertArrayHasKey('code', $body['items'][2]);
        self::assertSame(500, $body['refunded']);
    }

    public function testДеньгиСходятся(): void
    {
        $orderId = $this->settledOrder(['KEY-GTA5', 'KEY-SOLDOUT']);

        $money = $this->money($orderId);

        self::assertSame(249000, $money['paid']);
        self::assertSame(199000, $money['delivered']);
        self::assertSame(50000, $money['refunded']);
        self::assertSame(0, $money['open']);
        self::assertSame($money['paid'], $money['delivered'] + $money['refunded'] + $money['open']);
    }

    public function testНиОднаПозицияНеВыданаВозвращаетсяВсё(): void
    {
        $orderId = $this->settledOrder(['KEY-SOLDOUT', 'KEY-SOLDOUT']);

        self::assertSame([1 => OrderItem::REFUNDED, 2 => OrderItem::REFUNDED], $this->itemStatuses($orderId));
        self::assertSame(Order::REFUNDED, $this->orderStatus($orderId));

        $money = $this->money($orderId);
        self::assertSame($money['paid'], $money['refunded']);
        self::assertSame(0, $this->rows('deliveries', "status = 'delivered'"));
    }

    public function testПовторВсехШаговНичегоНеМеняет(): void
    {
        $orderId = $this->settledOrder(['KEY-GTA5', 'KEY-SOLDOUT']);

        $before = [$this->itemStatuses($orderId), $this->orderStatus($orderId), $this->money($orderId)];
        $entries = $this->rows('ledger_entries');

        // Повторная доставка платёжного события
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($orderId, ['amount' => 2490]));

        // Повторные выдача, возврат и доводка по каждой позиции
        foreach ([1, 2] as $position) {
            ($this->container->get(DeliverOrderItem::class))($orderId, $position);
            ($this->container->get(RefundItem::class))($orderId, $position);
        }

        ($this->container->get(FinalizeOrder::class))($orderId);
        ($this->container->get(HealStuckOrders::class))();

        self::assertSame(
            $before,
            [$this->itemStatuses($orderId), $this->orderStatus($orderId), $this->money($orderId)]
        );
        self::assertSame($entries, $this->rows('ledger_entries'), 'лишних проводок нет');
        self::assertSame(1, $this->rows('deliveries', "status = 'delivered'"));
    }

    public function testОбрывПосредиВыдачиДоводитсяВосстановителем(): void
    {
        $order = $this->createOrderWith(['KEY-GTA5', 'KEY-GTA5']);
        $orderId = $order['order_id'];

        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($orderId, ['amount' => 3980]));
        ($this->container->get(DeliverOrderItem::class))($orderId, 1);

        // Исполнитель остановлен между занятием позиции и записью результата.
        $this->db->execute(
            "INSERT INTO deliveries (order_id, position, status, claimed_at)
                  VALUES (?, 2, ?, now() - interval '1 hour')
             ON CONFLICT (order_id, position) DO UPDATE
                SET status = excluded.status, claimed_at = excluded.claimed_at",
            [$orderId, Delivery::IN_FLIGHT]
        );
        $this->db->execute(
            'UPDATE order_items SET status = ? WHERE order_id = ? AND position = 2',
            [OrderItem::DELIVERING, $orderId]
        );
        $this->db->execute('DELETE FROM jobs');
        $this->db->execute("UPDATE orders SET paid_at = now() - interval '1 hour' WHERE id = ?", [$orderId]);

        self::assertSame(Order::DELIVERING, $this->orderStatus($orderId), 'заказ завис');

        $healed = ($this->container->get(HealStuckOrders::class))();
        $this->runWorker();

        self::assertSame(1, $healed['released_items']);
        self::assertSame(1, $healed['requeued_items']);
        self::assertSame([1 => OrderItem::DELIVERED, 2 => OrderItem::DELIVERED], $this->itemStatuses($orderId));
        self::assertSame(Order::DELIVERED, $this->orderStatus($orderId));
        self::assertSame(2, $this->rows('deliveries', "status = 'delivered'"));
    }

    public function testЗаказНеЗавершаетсяПокаЕстьНезавершённыеПозиции(): void
    {
        $order = $this->createOrderWith(['KEY-GTA5', 'KEY-SOLDOUT']);
        $orderId = $order['order_id'];

        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($orderId, ['amount' => 2490]));
        $this->runWorker();

        self::assertSame(OrderItem::DELIVERED, $this->itemStatuses($orderId)[1]);
        self::assertSame(OrderItem::OUT_OF_STOCK, $this->itemStatuses($orderId)[2]);

        // Вторая позиция ещё может быть выдана после пополнения склада
        self::assertNull(($this->container->get(FinalizeOrder::class))($orderId));
        self::assertSame(Order::DELIVERING, $this->orderStatus($orderId));

        $this->db->execute(
            'INSERT INTO provider_stock (provider, sku, code) VALUES (?, ?, ?)',
            ['a', 'KEY-SOLDOUT', 'a-LATE-0001']
        );
        ($this->container->get(DeliverOrderItem::class))($orderId, 2);

        self::assertSame(Order::DELIVERED, $this->orderStatus($orderId));
        self::assertSame(0, $this->money($orderId)['refunded'], 'возврата не потребовалось');
    }

    public function testВозвратИзНеопределённостиЗапрещён(): void
    {
        $this->container->get(\App\Services\ProviderStub::class)
            ->configure('a', \App\Services\ProviderStub::ISSUE_THEN_TIMEOUT, 0.0, 0.0, 1);

        $order = $this->createOrder();
        $orderId = $order['order_id'];

        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($orderId));
        $this->runWorker();

        self::assertSame(OrderItem::UNRESOLVED, $this->itemStatuses($orderId)[1]);

        // Поставщик мог выдать код: возврат вернул бы деньги вместе с товаром.
        $deliver = $this->container->get(DeliverOrderItem::class);
        $deliver($orderId, 1);
        $deliver($orderId, 1);
        $deliver($orderId, 1);

        self::assertSame(OrderItem::UNRESOLVED, $this->itemStatuses($orderId)[1]);
        self::assertFalse(($this->container->get(RefundItem::class))($orderId, 1));
        self::assertSame(0, $this->money($orderId)['refunded']);

        // Неопределённость разрешается повтором к тому же поставщику.
        $this->container->get(\App\Services\ProviderStub::class)
            ->configure('a', \App\Services\ProviderStub::OK, 0.0, 0.0, 0);
        $deliver($orderId, 1);

        self::assertSame(OrderItem::DELIVERED, $this->itemStatuses($orderId)[1]);
        self::assertSame(Order::DELIVERED, $this->orderStatus($orderId));
        self::assertSame(1, $this->rows('provider_stock', "request_id LIKE '%$orderId%'"),
            'второго кода не занято');
    }
}
