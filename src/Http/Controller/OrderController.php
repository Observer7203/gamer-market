<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Database\Connection;
use App\Domain\Ordering\CreateOrder;
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
        $order = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$request->attribute('id')]);

        if ($order === null) {
            return Response::error('order_not_found', 'Заказ не найден', 404);
        }

        return Response::json($this->present($order));
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function present(array $order): array
    {
        $body = [
            'order_id'   => $order['id'],
            'sku'        => $order['sku'],
            'amount'     => (int) $order['price_minor'],
            'currency'   => $order['currency'],
            'status'     => $order['status'],
            'created_at' => $order['created_at'],
        ];

        // Код показывается только в финальном состоянии выдачи.
        $delivery = $this->db->selectOne(
            'SELECT code, delivered_at FROM deliveries WHERE order_id = ? AND status = \'delivered\'',
            [$order['id']]
        );

        if ($delivery !== null) {
            $body['delivery'] = [
                'code'         => $delivery['code'],
                'delivered_at' => $delivery['delivered_at'],
            ];
        }

        return $body;
    }
}
