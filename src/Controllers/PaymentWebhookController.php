<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\HandlePaymentWebhook;
use App\Support\Logger;
use Throwable;

/**
 * Приём вебхука платёжной системы.
 *
 * Разбор формата даёт код 400. Несоответствие данных заказу — не повод
 * для ошибки: повторная доставка того же события его не исправит, поэтому
 * событие принимается, помечается исходом и попадает в сверку.
 */
final class PaymentWebhookController
{
    public function __construct(
        private readonly HandlePaymentWebhook $handle,
        private readonly Logger $logger,
    ) {
    }

    public function store(Request $request): Response
    {
        $error = $this->validate($request);

        if ($error !== null) {
            return Response::error('invalid_request', $error, 400);
        }

        try {
            $outcome = ($this->handle)($request->json);
        } catch (Throwable $e) {
            $this->logger->error('webhook_failed', [
                'event_id'  => $request->input('event_id'),
                'order_id'  => $request->input('order_id'),
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            // Пятисотка заставит платёжную систему повторить доставку —
            // это и требуется при сбое на нашей стороне.
            return Response::error('internal_error', 'Не удалось обработать событие', 500);
        }

        return Response::json(['accepted' => true, 'outcome' => $outcome]);
    }

    private function validate(Request $request): ?string
    {
        foreach (['event_id', 'order_id', 'status', 'currency', 'created_at'] as $field) {
            if (!is_string($request->input($field)) || $request->input($field) === '') {
                return "Поле $field обязательно и должно быть непустой строкой";
            }
        }

        if (!in_array($request->input('status'), ['paid', 'failed'], true)) {
            return 'Поле status допускает значения paid и failed';
        }

        // Сумма обязана быть целым числом: нестрогое сравнение пропустило бы
        // строку "500" в денежную логику.
        if (!is_int($request->input('amount')) || $request->input('amount') <= 0) {
            return 'Поле amount обязательно и должно быть положительным целым числом';
        }

        if (strtotime((string) $request->input('created_at')) === false) {
            return 'Поле created_at должно содержать дату в формате ISO 8601';
        }

        return null;
    }
}
