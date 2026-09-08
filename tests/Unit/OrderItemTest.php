<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderItemTest extends TestCase
{
    /** @param array<string, mixed> $override */
    private function item(array $override = []): OrderItem
    {
        return OrderItem::fromRow($override + [
            'order_id'    => 'ord_TEST',
            'position'    => 1,
            'sku'         => 'KEY-GTA5',
            'price_minor' => 199000,
            'currency'    => 'RUB',
            'status'      => OrderItem::PENDING,
            'settled_at'  => null,
        ]);
    }

    #[DataProvider('allowedTransitions')]
    public function testРазрешённыеПереходы(string $from, string $to): void
    {
        self::assertTrue($this->item(['status' => $from])->canTransitionTo($to));
    }

    /** @return list<array{string, string}> */
    public static function allowedTransitions(): array
    {
        return [
            [OrderItem::PENDING, OrderItem::DELIVERING],
            [OrderItem::DELIVERING, OrderItem::DELIVERED],
            [OrderItem::DELIVERING, OrderItem::OUT_OF_STOCK],
            [OrderItem::DELIVERING, OrderItem::FAILED],
            [OrderItem::DELIVERING, OrderItem::UNRESOLVED],
            [OrderItem::OUT_OF_STOCK, OrderItem::REFUNDED],
            [OrderItem::FAILED, OrderItem::REFUNDED],
            [OrderItem::UNRESOLVED, OrderItem::DELIVERING],
            [OrderItem::UNRESOLVED, OrderItem::DELIVERED],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function testЗапрещённыеПереходы(string $from, string $to): void
    {
        self::assertFalse($this->item(['status' => $from])->canTransitionTo($to));
    }

    /** @return list<array{string, string}> */
    public static function forbiddenTransitions(): array
    {
        return [
            // Поставщик не ответил: код мог быть выдан, деньги возвращать нельзя
            [OrderItem::UNRESOLVED, OrderItem::REFUNDED],
            // Выданное не возвращается, возвращённое не выдаётся
            [OrderItem::DELIVERED, OrderItem::REFUNDED],
            [OrderItem::REFUNDED, OrderItem::DELIVERING],
            [OrderItem::REFUNDED, OrderItem::DELIVERED],
            // Через голову
            [OrderItem::PENDING, OrderItem::DELIVERED],
            [OrderItem::PENDING, OrderItem::REFUNDED],
        ];
    }

    public function testВозвратТолькоИзИзвестногоОтказа(): void
    {
        self::assertTrue($this->item(['status' => OrderItem::OUT_OF_STOCK])->isRefundable());
        self::assertTrue($this->item(['status' => OrderItem::FAILED])->isRefundable());

        self::assertFalse($this->item(['status' => OrderItem::UNRESOLVED])->isRefundable(),
            'состояние поставщика неизвестно');
        self::assertFalse($this->item(['status' => OrderItem::DELIVERING])->isRefundable());
        self::assertFalse($this->item(['status' => OrderItem::DELIVERED])->isRefundable());
    }

    public function testЗавершённыеСостояния(): void
    {
        self::assertTrue($this->item(['status' => OrderItem::DELIVERED])->isSettled());
        self::assertTrue($this->item(['status' => OrderItem::REFUNDED])->isSettled());

        foreach (OrderItem::unsettledStatuses() as $status) {
            self::assertFalse($this->item(['status' => $status])->isSettled(), $status);
        }
    }

    public function testИдентификаторЗапросаРазличаетПозиции(): void
    {
        $first  = $this->item(['position' => 1])->requestId('a');
        $second = $this->item(['position' => 2])->requestId('a');

        self::assertSame('req_ord_TEST_1_a', $first);
        self::assertNotSame($first, $second, 'соседние позиции не делят один запрос');
        self::assertNotSame($first, $this->item(['position' => 1])->requestId('b'));

        // Повтор той же позиции у того же поставщика даёт тот же запрос
        self::assertSame($first, $this->item(['position' => 1])->requestId('a'));
    }
}
