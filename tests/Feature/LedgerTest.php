<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Ledger;
use PDOException;
use Tests\TestCase;

/**
 * Журнал денежных движений: двойная запись и её баланс.
 */
final class LedgerTest extends TestCase
{
    private function ledger(): Ledger
    {
        return $this->container->get(Ledger::class);
    }

    /** @return array<string, int> */
    private function balances(): array
    {
        return $this->ledger()->balances();
    }

    /** Заказ из одной позиции, которую невозможно выдать: деньги возвращаются. */
    private function refundedOrder(): string
    {
        $this->db->raw('TRUNCATE provider_stock');

        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
        $this->runWorker();

        // Бюджет попыток исчерпывается прямыми вызовами: в очереди задача
        // ждала бы отсрочки.
        $deliver = $this->container->get(\App\Services\DeliverOrderItem::class);
        $deliver($order['order_id'], 1);
        $deliver($order['order_id'], 1);

        return $order['order_id'];
    }

    private function deliverPaidOrder(): string
    {
        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
        $this->runWorker();

        return $order['order_id'];
    }

    public function testОплатаСоздаётОбязательство(): void
    {
        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));

        $balances = $this->balances();

        self::assertSame(199000, $balances[Ledger::SETTLEMENT], 'средства поступили');
        self::assertSame(-199000, $balances[Ledger::OBLIGATION], 'товар должен быть выдан');
        self::assertSame(0, array_sum($balances), 'проводка сбалансирована');
    }

    public function testВыдачаЗакрываетОбязательство(): void
    {
        $this->deliverPaidOrder();

        $balances = $this->balances();

        self::assertSame(199000, $balances[Ledger::SETTLEMENT]);
        self::assertSame(0, $balances[Ledger::OBLIGATION], 'обязательство исполнено');
        self::assertSame(-199000, $balances[Ledger::REVENUE], 'выручка признана');
        self::assertSame(0, array_sum($balances));
    }

    public function testЖурналСходитсяПриЛюбомЧислеЗаказов(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->deliverPaidOrder();
        }

        self::assertSame(0, array_sum($this->balances()));
        self::assertSame([], $this->ledger()->imbalanced());
        self::assertSame(12, $this->rows('ledger_entries'), 'по четыре строки на заказ');
    }

    public function testПовторнаяДоставкаСобытияНеУдваиваетПроводку(): void
    {
        $order = $this->createOrder();
        $event = $this->paymentEvent($order['order_id']);

        $this->request('POST', '/api/webhooks/payment', $event);
        $this->request('POST', '/api/webhooks/payment', $event);

        // Идентификатор проводки выводится из события: повтор ничего не добавит.
        self::assertSame(2, $this->rows('ledger_entries'));
        self::assertSame(199000, $this->balances()[Ledger::SETTLEMENT]);
    }

    public function testПовторВыдачиНеУдваиваетПроводку(): void
    {
        $orderId = $this->deliverPaidOrder();

        ($this->container->get(\App\Services\DeliverOrderItem::class))($orderId, 1);

        self::assertSame(4, $this->rows('ledger_entries'));
        self::assertSame(0, array_sum($this->balances()));
    }

    public function testВозвратЗакрываетОбязательство(): void
    {
        $orderId = $this->refundedOrder();

        $balances = $this->balances();

        self::assertSame(199000, $balances[Ledger::SETTLEMENT]);
        self::assertSame(0, $balances[Ledger::OBLIGATION], 'обязательство исполнено деньгами');
        self::assertSame(-199000, $balances[Ledger::REFUND]);
        self::assertSame(0, array_sum($balances));
        self::assertSame(\App\Models\Order::REFUNDED, $this->orderStatus($orderId));
    }

    public function testПовторВозвратаНеУдваиваетПроводку(): void
    {
        $orderId = $this->refundedOrder();

        $repeated = ($this->container->get(\App\Services\RefundItem::class))($orderId, 1);

        self::assertFalse($repeated, 'позиция уже возвращена');
        self::assertSame(4, $this->rows('ledger_entries'));
        self::assertSame(0, array_sum($this->balances()));
    }

    public function testДеньгиСходятсяПриЧастичнойВыдаче(): void
    {
        $this->seedProduct('KEY-NOSTOCK', 50000, 0);

        $order = $this->createOrderWith(['KEY-GTA5', 'KEY-NOSTOCK']);
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent(
            $order['order_id'],
            ['amount' => 2490]
        ));
        $this->runWorker();

        // Позиция без остатка добирает попытки и возвращается покупателю
        $deliver = $this->container->get(\App\Services\DeliverOrderItem::class);
        $deliver($order['order_id'], 2);
        $deliver($order['order_id'], 2);

        $balances = $this->balances();

        self::assertSame(249000, $balances[Ledger::SETTLEMENT]);
        self::assertSame(-199000, $balances[Ledger::REVENUE]);
        self::assertSame(-50000, $balances[Ledger::REFUND]);
        self::assertSame(0, $balances[Ledger::OBLIGATION]);
        self::assertSame(0, array_sum($balances), 'оплачено = выдано + возвращено');
    }

    public function testНесбалансированнаяПроводкаНеФиксируется(): void
    {
        $order = $this->createOrder();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/не сходится/');

        // Отложенный триггер проверяет сумму в момент фиксации транзакции.
        $this->db->transaction(function () use ($order): void {
            $this->db->execute(
                'INSERT INTO ledger_entries (txn_id, order_id, account, amount_minor, currency, reason)
                      VALUES (?, ?, ?, ?, ?, ?)',
                ['broken', $order['order_id'], Ledger::SETTLEMENT, 100000, 'RUB', 'test']
            );
        });
    }

    public function testНеудачныйПлатёжНеСоздаётПроводок(): void
    {
        $order = $this->createOrder();

        $this->request(
            'POST',
            '/api/webhooks/payment',
            $this->paymentEvent($order['order_id'], ['status' => 'failed'])
        );

        self::assertSame(0, $this->rows('ledger_entries'));
    }

    public function testОбязательствоРавноНевыданнымОплаченнымЗаказам(): void
    {
        // Один заказ выдан, второй оплачен и не выдан.
        $this->deliverPaidOrder();

        $stuck = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($stuck['order_id']));

        self::assertSame(-199000, $this->balances()[Ledger::OBLIGATION],
            'баланс обязательств равен сумме оплаченных, но не выданных заказов');
    }
}
