<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

/**
 * Правила жизненного цикла заказа: перечень состояний, разрешённые переходы,
 * генерация идентификатора.
 *
 * Переходы заданы белым списком — разрешено только перечисленное. Проверка
 * живёт здесь, а не в контроллере или сервисе: состоянием владеет заказ.
 */
final class Order
{
    public const CREATED         = 'created';
    public const PAID            = 'paid';
    public const DELIVERING      = 'delivering';
    public const DELIVERED       = 'delivered';
    public const PAYMENT_FAILED  = 'payment_failed';
    public const OUT_OF_STOCK    = 'out_of_stock';
    public const DELIVERY_FAILED = 'delivery_failed';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::CREATED         => [self::PAID, self::PAYMENT_FAILED],
        self::PAID            => [self::DELIVERING],
        self::DELIVERING      => [self::DELIVERED, self::OUT_OF_STOCK, self::DELIVERY_FAILED],

        self::DELIVERED       => [],
        self::PAYMENT_FAILED  => [],

        // Восстановимые: повторная выдача идёт с тем же request_id,
        // поэтому возврат в delivering не создаёт вторую выдачу.
        self::OUT_OF_STOCK    => [self::DELIVERING],
        self::DELIVERY_FAILED => [self::DELIVERING],
    ];

    private const FINAL = [self::DELIVERED, self::PAYMENT_FAILED];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL, true);
    }

    /**
     * ULID с префиксом: лексикографически сортируемый и монотонный по времени,
     * поэтому вставки не рандомизируют порядок в btree-индексе.
     */
    public static function newId(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = (int) (microtime(true) * 1000);

        $id = '';
        for ($i = 9; $i >= 0; $i--) {
            $id = $alphabet[$time % 32] . $id;
            $time = intdiv($time, 32);
        }
        for ($i = 0; $i < 16; $i++) {
            $id .= $alphabet[random_int(0, 31)];
        }

        return 'ord_' . $id;
    }
}
