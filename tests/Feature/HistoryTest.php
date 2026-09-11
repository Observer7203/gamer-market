<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\DeliverOrderItem;
use App\Services\Reconciliation;
use App\Services\TemporalState;
use PDOException;
use Tests\TestCase;

/**
 * Восстановление картины на прошлый момент.
 *
 * Текущее состояние перезаписывается при каждом изменении, поэтому ответ
 * на вопрос «что было тогда» даёт журнал событий. Он только дополняется,
 * и пишет его база, а не приложение.
 */
final class HistoryTest extends TestCase
{
    private function history(): TemporalState
    {
        return $this->container->get(TemporalState::class);
    }

    /** Момент с долями секунды: внутри секунды заказ проходит несколько состояний. */
    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP');
    }

    private function paidOrder(): string
    {
        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));

        return $order['order_id'];
    }

    public function testСостояниеЗаказаНаМомент(): void
    {
        $orderId = $this->paidOrder();
        $beforeDelivery = $this->now();

        $this->runWorker();
        $afterDelivery = $this->now();

        $before = $this->history()->orderAt($orderId, $beforeDelivery);
        $after = $this->history()->orderAt($orderId, $afterDelivery);

        self::assertSame(Order::PAID, $before['status']);
        self::assertSame(OrderItem::PENDING, $before['items'][0]['status']);
        self::assertNull($before['items'][0]['code'], 'кода тогда ещё не было');

        self::assertSame(Order::DELIVERED, $after['status']);
        self::assertSame(OrderItem::DELIVERED, $after['items'][0]['status']);
        self::assertNotNull($after['items'][0]['code']);
    }

    public function testДоСозданияЗаказаНетНичего(): void
    {
        $before = $this->now();
        $orderId = $this->paidOrder();

        self::assertNull($this->history()->orderAt($orderId, $before));
        self::assertNotNull($this->history()->orderAt($orderId, $this->now()));
    }

    public function testДеньгиНаМомент(): void
    {
        $beforeAll = $this->now();
        $orderId = $this->paidOrder();
        $afterPayment = $this->now();

        $this->runWorker();
        $afterDelivery = $this->now();

        self::assertSame(0, $this->history()->moneyAt($beforeAll)['paid_minor']);

        $paid = $this->history()->moneyAt($afterPayment);
        self::assertSame(199000, $paid['paid_minor']);
        self::assertSame(0, $paid['delivered_minor'], 'на тот момент ещё не выдано');
        self::assertSame(199000, $paid['in_progress_minor']);
        self::assertTrue($paid['balanced']);

        $delivered = $this->history()->moneyAt($afterDelivery);
        self::assertSame(199000, $delivered['delivered_minor']);
        self::assertSame(0, $delivered['in_progress_minor']);
        self::assertTrue($delivered['balanced']);
    }

    public function testЧастичнаяВыдачаВосстанавливаетсяПоШагам(): void
    {
        $this->seedProduct('KEY-SOLDOUT', 50000, 0);

        $order = $this->createOrderWith(['KEY-GTA5', 'KEY-SOLDOUT']);
        $orderId = $order['order_id'];

        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($orderId, ['amount' => 2490]));
        $this->runWorker();

        $betweenSteps = $this->now();

        // Вторая позиция добирает попытки и возвращается покупателю.
        $deliver = $this->container->get(DeliverOrderItem::class);
        $deliver($orderId, 2);
        $deliver($orderId, 2);

        $middle = $this->history()->orderAt($orderId, $betweenSteps);
        $final = $this->history()->orderAt($orderId, $this->now());

        self::assertSame(Order::DELIVERING, $middle['status'], 'тогда заказ ещё не был завершён');
        self::assertSame(OrderItem::DELIVERED, $middle['items'][0]['status']);
        self::assertSame(OrderItem::OUT_OF_STOCK, $middle['items'][1]['status']);
        self::assertSame(0, $middle['money']['refunded_minor'], 'возврата тогда не было');
        self::assertSame(50000, $middle['money']['in_progress_minor']);

        self::assertSame(Order::PARTIALLY_DELIVERED, $final['status']);
        self::assertSame(OrderItem::REFUNDED, $final['items'][1]['status']);
        self::assertSame(50000, $final['money']['refunded_minor']);
    }

    public function testИсторияТолькоДополняется(): void
    {
        $this->paidOrder();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/только дополняется/');

        $this->db->execute("UPDATE order_events SET to_status = 'подделка'");
    }

    public function testУдалениеИзИсторииЗапрещено(): void
    {
        $this->paidOrder();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/только дополняется/');

        $this->db->execute('DELETE FROM order_events');
    }

    public function testЖурналДенегТожеТолькоДополняется(): void
    {
        $this->paidOrder();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/только дополняется/');

        $this->db->execute('UPDATE ledger_entries SET amount_minor = 1');
    }

    public function testИсториюПишетБазаАНеПриложение(): void
    {
        $orderId = $this->paidOrder();
        $before = $this->rows('order_events');

        // Изменение в обход всех сервисов, прямым запросом.
        $this->db->execute(
            "UPDATE order_items SET status = 'delivering' WHERE order_id = ?",
            [$orderId]
        );

        self::assertSame($before + 1, $this->rows('order_events'), 'событие записано триггером');

        $last = $this->db->selectOne('SELECT * FROM order_events ORDER BY id DESC LIMIT 1');
        self::assertSame(OrderItem::PENDING, (string) $last['from_status']);
        self::assertSame(OrderItem::DELIVERING, (string) $last['to_status']);
    }

    public function testИзменениеСтатусаНаТотЖеНеПлодитСобытий(): void
    {
        $orderId = $this->paidOrder();
        $before = $this->rows('order_events');

        $this->db->execute("UPDATE order_items SET status = status WHERE order_id = ?", [$orderId]);

        self::assertSame($before, $this->rows('order_events'));
    }

    public function testИтогиЗаПериодСходятся(): void
    {
        $from = $this->now();

        $first = $this->paidOrder();
        $this->runWorker();

        $middle = $this->now();

        $this->paidOrder();
        $this->runWorker();

        $to = $this->now();

        $whole = $this->history()->periodTotals($from, $to);
        $tail = $this->history()->periodTotals($middle, $to);

        self::assertTrue($whole['consistent'], 'на конец = на начало + движения');
        self::assertTrue($whole['movement_balanced']);
        self::assertSame(2, $whole['orders']['paid']);
        self::assertSame(2, $whole['orders']['items_delivered']);
        self::assertSame(398000, $whole['movement']['paid_minor']);

        // Второй период начинается там, где первый ещё не кончился.
        self::assertSame(199000, $tail['opening']['paid_minor'], 'остаток на начало — первый заказ');
        self::assertSame(199000, $tail['movement']['paid_minor']);
        self::assertSame(398000, $tail['closing']['paid_minor']);
        self::assertTrue($tail['consistent']);
    }

    public function testПериодыСтыкуютсяБезРазрывовИНахлёстов(): void
    {
        $from = $this->now();
        $this->paidOrder();
        $this->runWorker();
        $middle = $this->now();
        $this->paidOrder();
        $this->runWorker();
        $to = $this->now();

        $first = $this->history()->periodTotals($from, $middle);
        $second = $this->history()->periodTotals($middle, $to);
        $whole = $this->history()->periodTotals($from, $to);

        // Граница принадлежит первому периоду: момент задаётся включительно.
        self::assertSame(
            $whole['movement']['paid_minor'],
            $first['movement']['paid_minor'] + $second['movement']['paid_minor'],
            'сумма частей равна целому',
        );
    }

    public function testСостояниеСовпадаетСИсторией(): void
    {
        $this->paidOrder();
        $this->runWorker();

        self::assertSame([], $this->history()->mismatches());

        $report = $this->container->get(Reconciliation::class)->report();
        self::assertSame(0, $report['summary']['history_mismatches']);
        self::assertTrue($report['healthy']);
    }

    public function testРасхождениеСИсториейОбнаруживается(): void
    {
        $orderId = $this->paidOrder();

        // Изменение с отключённым триггером: единственный способ поменять
        // состояние, не оставив следа в истории.
        $this->db->raw('ALTER TABLE order_items DISABLE TRIGGER order_items_history');
        $this->db->execute("UPDATE order_items SET status = 'delivered' WHERE order_id = ?", [$orderId]);
        $this->db->raw('ALTER TABLE order_items ENABLE TRIGGER order_items_history');

        $mismatches = $this->history()->mismatches();

        self::assertCount(1, $mismatches);
        self::assertSame(OrderItem::DELIVERED, (string) $mismatches[0]['текущее']);
        self::assertSame(OrderItem::PENDING, (string) $mismatches[0]['по_истории']);
        self::assertFalse($this->container->get(Reconciliation::class)->report()['healthy']);
    }

    public function testЛентаСобытийЗаказа(): void
    {
        $orderId = $this->paidOrder();
        $this->runWorker();

        $timeline = $this->history()->timeline($orderId);
        $statuses = array_column($timeline, 'to_status');

        self::assertSame(
            [Order::CREATED, OrderItem::PENDING, Order::PAID, OrderItem::DELIVERING,
             Order::DELIVERING, OrderItem::DELIVERED, Order::DELIVERED],
            $statuses,
        );
    }

    public function testСостояниеНаМоментОтдаётсяПоHttp(): void
    {
        $orderId = $this->paidOrder();
        $beforeDelivery = $this->now();
        $this->runWorker();

        $response = $this->request('GET', '/api/admin/history/orders/' . $orderId, ['at' => $beforeDelivery]);

        self::assertSame(200, $response->status);
        self::assertSame(Order::PAID, $response->body['status']);
        self::assertSame(1990, $response->body['amount']);
        self::assertNull($response->body['items'][0]['code']);
        self::assertTrue($response->body['money']['balanced']);
        self::assertNotEmpty($response->body['timeline']);
    }

    public function testДеньгиИИтогиОтдаютсяПоHttp(): void
    {
        $from = $this->now();
        $this->paidOrder();
        $this->runWorker();

        $money = $this->request('GET', '/api/admin/history/money', ['at' => $this->now()]);
        self::assertSame(200, $money->status);
        self::assertSame(1990, $money->body['paid']);
        self::assertTrue($money->body['balanced']);

        $period = $this->request('GET', '/api/admin/history/period', [
            'from' => $from,
            'to'   => $this->now(),
        ]);

        self::assertSame(200, $period->status);
        self::assertTrue($period->body['consistent']);
        self::assertSame(1990, $period->body['movement']['paid']);
    }

    public function testНеверныйМоментОтклоняется(): void
    {
        $orderId = $this->paidOrder();

        $response = $this->request('GET', '/api/admin/history/orders/' . $orderId, ['at' => 'вчера-когда-то']);

        self::assertSame(400, $response->status);
        self::assertSame('invalid_request', $response->body['error']['code']);
    }
}
