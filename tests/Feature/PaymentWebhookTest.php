<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use Tests\TestCase;

final class PaymentWebhookTest extends TestCase
{
    public function testОплатаПереводитЗаказВPaid(): void
    {
        $order = $this->createOrder();

        $response = $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));

        self::assertSame(200, $response->status);
        self::assertSame('applied', $response->body['outcome']);
        self::assertSame(Order::PAID, $this->orderStatus($order['order_id']));
        self::assertSame(1, $this->rows('jobs'), 'задача на выдачу создана');
    }

    public function testПовторногоСобытияНедостаточноДляВтороройЗадачи(): void
    {
        $order = $this->createOrder();
        $event = $this->paymentEvent($order['order_id']);

        $first = $this->request('POST', '/api/webhooks/payment', $event);
        $second = $this->request('POST', '/api/webhooks/payment', $event);

        self::assertSame('applied', $first->body['outcome']);
        self::assertSame('duplicate', $second->body['outcome']);
        self::assertSame(200, $second->status, 'дубликат не является ошибкой');
        self::assertSame(1, $this->rows('jobs'));
        self::assertSame(1, $this->rows('payment_events'));
    }

    public function testНовоеСобытиеПоУжеОплаченномуЗаказу(): void
    {
        $order = $this->createOrder();

        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));
        $second = $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));

        self::assertSame('ignored', $second->body['outcome']);
        self::assertSame(1, $this->rows('jobs'), 'вторая задача не создана');
    }

    public function testНесоответствиеСуммы(): void
    {
        $order = $this->createOrder();

        $response = $this->request(
            'POST',
            '/api/webhooks/payment',
            $this->paymentEvent($order['order_id'], ['amount' => 500])
        );

        // Ответ успешный: повтор доставки сумму не исправит, разбирается сверкой
        self::assertSame(200, $response->status);
        self::assertSame('amount_mismatch', $response->body['outcome']);
        self::assertSame(Order::CREATED, $this->orderStatus($order['order_id']));
        self::assertSame(0, $this->rows('jobs'));
    }

    public function testНесоответствиеВалюты(): void
    {
        $order = $this->createOrder();

        $response = $this->request(
            'POST',
            '/api/webhooks/payment',
            $this->paymentEvent($order['order_id'], ['currency' => 'USD'])
        );

        self::assertSame('amount_mismatch', $response->body['outcome']);
    }

    public function testСобытиеПоНесуществующемуЗаказуСохраняется(): void
    {
        $response = $this->request('POST', '/api/webhooks/payment', $this->paymentEvent('ord_NETAKOGO'));

        self::assertSame(200, $response->status);
        self::assertSame('order_not_found', $response->body['outcome']);
        self::assertSame(1, $this->rows('payment_events', "processed_at IS NULL"));
    }

    public function testНеудачныйПлатёжПереводитВPaymentFailed(): void
    {
        $order = $this->createOrder();

        $this->request(
            'POST',
            '/api/webhooks/payment',
            $this->paymentEvent($order['order_id'], ['status' => 'failed'])
        );

        self::assertSame(Order::PAYMENT_FAILED, $this->orderStatus($order['order_id']));
        self::assertSame(0, $this->rows('jobs'));
    }

    public function testСобытиеСозданноеРаньшеНеОтменяетПрименённое(): void
    {
        $order = $this->createOrder();

        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id'], [
            'status'     => 'paid',
            'created_at' => '2026-01-01T12:00:00Z',
        ]));

        // Отказ создан раньше оплаты, но доставлен позже
        $late = $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id'], [
            'status'     => 'failed',
            'created_at' => '2026-01-01T11:00:00Z',
        ]));

        self::assertSame('superseded', $late->body['outcome']);
        self::assertSame(Order::PAID, $this->orderStatus($order['order_id']));
    }

    /** @param array<string, mixed> $payload */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function testОшибкиФорматаДаютЧетырестаБезЗаписи(array $payload, string $expectation): void
    {
        $response = $this->request('POST', '/api/webhooks/payment', $payload);

        self::assertSame(400, $response->status, $expectation);
        self::assertSame('invalid_request', $response->body['error']['code']);
        self::assertSame(0, $this->rows('payment_events'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): array
    {
        $valid = [
            'event_id'   => 'evt_1',
            'order_id'   => 'ord_1',
            'status'     => 'paid',
            'amount'     => 1990,
            'currency'   => 'RUB',
            'created_at' => '2026-01-01T12:00:00Z',
        ];

        return [
            'пустое тело'        => [[], 'нет обязательных полей'],
            'нет event_id'       => [array_diff_key($valid, ['event_id' => null]), 'без ключа идемпотентности'],
            'сумма строкой'      => [['amount' => '1990'] + $valid, 'строка вместо числа в денежном поле'],
            'сумма нулевая'      => [['amount' => 0] + $valid, 'ноль не является платежом'],
            'сумма отрицательная' => [['amount' => -100] + $valid, 'отрицательный платёж'],
            'неизвестный статус' => [['status' => 'refunded'] + $valid, 'статус вне контракта'],
            'дата не разбирается' => [['created_at' => 'вчера'] + $valid, 'некорректная дата'],
        ];
    }
}
