<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    /** @param array<string, mixed> $override */
    private function order(array $override = []): Order
    {
        return Order::fromRow($override + [
            'id'           => 'ord_TEST',
            'sku'          => 'KEY-GTA5',
            'price_minor'  => 199000,
            'currency'     => 'RUB',
            'status'       => Order::CREATED,
            'created_at'   => '2026-01-01 00:00:00+00',
            'paid_at'      => null,
            'delivered_at' => null,
        ]);
    }

    #[DataProvider('allowedTransitions')]
    public function testРазрешённыеПереходы(string $from, string $to): void
    {
        self::assertTrue($this->order(['status' => $from])->canTransitionTo($to));
    }

    /** @return list<array{string, string}> */
    public static function allowedTransitions(): array
    {
        return [
            [Order::CREATED, Order::PAID],
            [Order::CREATED, Order::PAYMENT_FAILED],
            [Order::PAID, Order::DELIVERING],
            [Order::DELIVERING, Order::DELIVERED],
            [Order::DELIVERING, Order::OUT_OF_STOCK],
            [Order::DELIVERING, Order::DELIVERY_FAILED],
            [Order::OUT_OF_STOCK, Order::DELIVERING],
            [Order::DELIVERY_FAILED, Order::DELIVERING],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function testЗапрещённыеПереходы(string $from, string $to): void
    {
        self::assertFalse($this->order(['status' => $from])->canTransitionTo($to));
    }

    /** @return list<array{string, string}> */
    public static function forbiddenTransitions(): array
    {
        return [
            // Через голову: оплата не выдаёт товар сама по себе
            [Order::CREATED, Order::DELIVERED],
            [Order::CREATED, Order::DELIVERING],
            // Из финальных состояний выхода нет
            [Order::DELIVERED, Order::PAID],
            [Order::DELIVERED, Order::DELIVERING],
            [Order::PAYMENT_FAILED, Order::PAID],
            // Назад по основному пути
            [Order::PAID, Order::CREATED],
            [Order::DELIVERING, Order::PAID],
        ];
    }

    public function testФинальныеСостояния(): void
    {
        self::assertTrue($this->order(['status' => Order::DELIVERED])->isFinal());
        self::assertTrue($this->order(['status' => Order::PAYMENT_FAILED])->isFinal());

        // Восстановимые финальными не являются
        self::assertFalse($this->order(['status' => Order::OUT_OF_STOCK])->isFinal());
        self::assertFalse($this->order(['status' => Order::DELIVERY_FAILED])->isFinal());
    }

    public function testСверкаСуммыИВалюты(): void
    {
        $order = $this->order();

        self::assertTrue($order->matches(199000, 'RUB'));
        self::assertFalse($order->matches(50000, 'RUB'), 'другая сумма');
        self::assertFalse($order->matches(199000, 'USD'), 'другая валюта');
    }

    public function testИдентификаторСортируемПоВремени(): void
    {
        $first = Order::newId();
        usleep(2000);
        $second = Order::newId();

        self::assertStringStartsWith('ord_', $first);
        self::assertNotSame($first, $second);
        self::assertLessThan(0, strcmp($first, $second), 'более поздний id должен быть больше');
    }
}
