<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Logger;

/**
 * Перевод заказа в конечное состояние по состояниям его позиций.
 *
 * Состояние заказа — производная величина: выдано всё, часть или ничего.
 * Хранится оно отдельным полем ради простых запросов, но источником правды
 * остаются позиции, поэтому пересчёт возможен в любой момент.
 *
 * Пересчёт выражен одним условным обновлением. Оно ничего не меняет, пока
 * есть незавершённые позиции, и ничего не меняет повторно — поэтому его
 * безопасно вызывать после каждой выдачи, после каждого возврата
 * и из восстановителя.
 */
final class FinalizeOrder
{
    public function __construct(
        private readonly Connection $db,
        private readonly Logger $logger,
    ) {
    }

    /** @return string|null новое состояние заказа либо null, если менять нечего */
    public function __invoke(string $orderId): ?string
    {
        $row = $this->db->selectOne(
            'UPDATE orders o
                SET status = CASE
                        WHEN s.delivered = s.total THEN ?
                        WHEN s.delivered = 0       THEN ?
                        ELSE ?
                    END,
                    delivered_at = CASE WHEN s.delivered > 0 THEN now() ELSE o.delivered_at END
               FROM (
                    SELECT order_id,
                           count(*)                                        AS total,
                           count(*) FILTER (WHERE status = ?)              AS delivered,
                           count(*) FILTER (WHERE settled_at IS NULL)      AS unsettled
                      FROM order_items
                     WHERE order_id = ?
                     GROUP BY order_id
               ) s
              WHERE o.id = s.order_id
                AND s.unsettled = 0
                AND o.status IN (?, ?)
          RETURNING o.status',
            [
                Order::DELIVERED, Order::REFUNDED, Order::PARTIALLY_DELIVERED,
                OrderItem::DELIVERED, $orderId,
                Order::PAID, Order::DELIVERING,
            ]
        );

        if ($row === null) {
            return null;
        }

        $status = (string) $row['status'];

        $this->logger->info('order_finalized', [
            'channel'  => 'order',
            'order_id' => $orderId,
            'status'   => $status,
        ]);

        return $status;
    }
}
