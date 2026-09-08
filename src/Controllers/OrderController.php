<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Exceptions\ProductNotFound;
use App\Http\Request;
use App\Http\Response;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CreateOrder;
use App\Support\Money;

final class OrderController
{
    public function __construct(
        private readonly CreateOrder $createOrder,
        private readonly Connection $db,
    ) {
    }

    public function store(Request $request): Response
    {
        try {
            $skus = $this->skus($request);
        } catch (\InvalidArgumentException $e) {
            return Response::error('invalid_request', $e->getMessage(), 400);
        }

        try {
            $created = ($this->createOrder)($skus);
        } catch (ProductNotFound $e) {
            return Response::error('product_not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException $e) {
            return Response::error('invalid_request', $e->getMessage(), 400);
        }

        return Response::json($this->present($created['order']), 201);
    }

    public function show(Request $request): Response
    {
        $row = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$request->attribute('id')]);

        if ($row === null) {
            return Response::error('order_not_found', 'Заказ не найден', 404);
        }

        return Response::json($this->present(Order::fromRow($row)));
    }

    /**
     * Разбор состава заказа.
     *
     * Одиночный sku остаётся допустимым: заказ из одной позиции —
     * частный случай общего вида, а не отдельный сценарий.
     *
     * @return list<string>
     */
    private function skus(Request $request): array
    {
        $items = $request->input('items');

        if ($items === null) {
            $sku = $request->input('sku');

            if (!is_string($sku) || $sku === '') {
                throw new \InvalidArgumentException('Требуется поле sku либо непустой массив items');
            }

            return [$sku];
        }

        if (!is_array($items) || $items === []) {
            throw new \InvalidArgumentException('Поле items должно быть непустым массивом позиций');
        }

        $skus = [];

        foreach ($items as $item) {
            $sku = is_array($item) ? ($item['sku'] ?? null) : $item;

            if (!is_string($sku) || $sku === '') {
                throw new \InvalidArgumentException('Каждая позиция должна содержать непустое поле sku');
            }

            $quantity = is_array($item) ? (int) ($item['quantity'] ?? 1) : 1;

            if ($quantity < 1) {
                throw new \InvalidArgumentException('Количество должно быть положительным');
            }

            // Каждая единица товара — отдельная позиция: у неё свой код,
            // свой поставщик и свой исход.
            for ($i = 0; $i < $quantity; $i++) {
                $skus[] = $sku;
            }
        }

        return $skus;
    }

    /** @return array<string, mixed> */
    private function present(Order $order): array
    {
        $rows = $this->db->select(
            'SELECT i.*, d.status AS delivery_status, d.code, d.delivered_at AS code_delivered_at
               FROM order_items i
               LEFT JOIN deliveries d ON d.order_id = i.order_id AND d.position = i.position
              WHERE i.order_id = ?
              ORDER BY i.position',
            [$order->id]
        );

        $items = [];
        $refunded = 0;

        foreach ($rows as $row) {
            $item = OrderItem::fromRow($row);

            $body = [
                'position' => $item->position,
                'sku'      => $item->sku,
                'amount'   => Money::toContract($item->priceMinor),
                'status'   => $item->status,
            ];

            // Код показывается только после успешной выдачи.
            if ($item->isDelivered() && $row['code'] !== null) {
                $body['code'] = (string) $row['code'];
                $body['delivered_at'] = (string) $row['code_delivered_at'];
            }

            if ($item->status === OrderItem::REFUNDED) {
                $body['refunded_at'] = $item->settledAt;
                $refunded += $item->priceMinor;
            }

            $items[] = $body;
        }

        return [
            'order_id'   => $order->id,
            'amount'     => Money::toContract($order->priceMinor),
            'refunded'   => Money::toContract($refunded),
            'currency'   => $order->currency,
            'status'     => $order->status,
            'created_at' => $order->createdAt,
            'items'      => $items,
        ];
    }
}
