<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Database\Connection;
use App\Domain\Delivery\Delivery;
use App\Domain\Money;
use App\Domain\Ordering\CreateOrder;
use App\Domain\Ordering\Order;
use App\Domain\Ordering\ProductNotFound;
use App\Http\Request;
use App\Http\Response;

final class OrderController
{
    public function __construct(
        private readonly CreateOrder $createOrder,
        private readonly Connection $db,
    ) {
    }

    public function store(Request $request): Response
    {
        $sku = $request->input('sku');

        if (!is_string($sku) || $sku === '') {
            return Response::error('invalid_request', 'Поле sku обязательно и должно быть строкой', 400);
        }

        try {
            $order = ($this->createOrder)($sku);
        } catch (ProductNotFound $e) {
            return Response::error('product_not_found', $e->getMessage(), 404);
        }

        return Response::json($this->present($order), 201);
    }

    public function show(Request $request): Response
    {
        $row = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$request->attribute('id')]);

        if ($row === null) {
            return Response::error('order_not_found', 'Заказ не найден', 404);
        }

        return Response::json($this->present(Order::fromRow($row)));
    }

    /** @return array<string, mixed> */
    private function present(Order $order): array
    {
        $body = [
            'order_id'   => $order->id,
            'sku'        => $order->sku,
            'amount'     => Money::toContract($order->priceMinor),
            'currency'   => $order->currency,
            'status'     => $order->status,
            'created_at' => $order->createdAt,
        ];

        $row = $this->db->selectOne('SELECT * FROM deliveries WHERE order_id = ?', [$order->id]);

        // Код показывается только после успешной выдачи.
        if ($row !== null) {
            $delivery = Delivery::fromRow($row);

            if ($delivery->isDelivered()) {
                $body['delivery'] = [
                    'code'         => $delivery->code,
                    'delivered_at' => $delivery->deliveredAt,
                ];
            }
        }

        return $body;
    }
}
