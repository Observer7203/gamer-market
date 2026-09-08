<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Models\OrderItem;
use App\Support\Logger;

/**
 * Возврат денег за невыданную позицию.
 *
 * Возврат допустим только из состояний, в которых точно известно, что кода
 * не было: пустой остаток и явный отказ поставщика. Из состояния «ответа
 * нет» возврат запрещён — код мог быть выдан, и деньги вернулись бы вместе
 * с работающим товаром.
 *
 * Однократность обеспечена самим условием обновления: вторая попытка не
 * находит подходящей строки и не создаёт проводку. Проверка перед записью
 * этого не дала бы — между чтением и записью успевает вклиниться другой
 * процесс.
 */
final class RefundItem
{
    public function __construct(
        private readonly Connection $db,
        private readonly Ledger $ledger,
        private readonly FinalizeOrder $finalizeOrder,
        private readonly Logger $logger,
    ) {
    }

    /** @return bool выполнен ли возврат этим вызовом */
    public function __invoke(string $orderId, int $position): bool
    {
        $refunded = $this->db->transaction(
            function (Connection $db) use ($orderId, $position): bool {
                $item = $db->selectOne(
                    'UPDATE order_items
                        SET status = ?, settled_at = now()
                      WHERE order_id = ? AND position = ? AND status IN (?, ?)
                  RETURNING price_minor, currency, sku',
                    [OrderItem::REFUNDED, $orderId, $position,
                     OrderItem::OUT_OF_STOCK, OrderItem::FAILED]
                );

                if ($item === null) {
                    return false;
                }

                $this->ledger->recordRefund(
                    $orderId,
                    $position,
                    (int) $item['price_minor'],
                    (string) $item['currency'],
                );

                $this->logger->info('item_refunded', [
                    'channel'      => 'order',
                    'order_id'     => $orderId,
                    'position'     => $position,
                    'sku'          => (string) $item['sku'],
                    'amount_minor' => (int) $item['price_minor'],
                ]);

                return true;
            }
        );

        if ($refunded) {
            ($this->finalizeOrder)($orderId);
        }

        return $refunded;
    }
}
