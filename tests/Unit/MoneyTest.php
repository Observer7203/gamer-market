<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testПереводВМинорныеЕдиницы(): void
    {
        self::assertSame(199000, Money::toMinor(1990));
        self::assertSame(100, Money::toMinor(1));
    }

    public function testПереводВЕдиницыКонтракта(): void
    {
        self::assertSame(1990, Money::toContract(199000));
        self::assertSame(1, Money::toContract(100));
    }

    public function testПреобразованиеОбратимо(): void
    {
        foreach ([1, 299, 1990, 3490, 999999] as $amount) {
            self::assertSame($amount, Money::toContract(Money::toMinor($amount)));
        }
    }

    public function testРезультатВсегдаЦелый(): void
    {
        // Дробные суммы контракт не предусматривает: остаток отбрасывается,
        // а не превращается в число с плавающей точкой.
        self::assertIsInt(Money::toContract(199050));
        self::assertSame(1990, Money::toContract(199050));
    }
}
