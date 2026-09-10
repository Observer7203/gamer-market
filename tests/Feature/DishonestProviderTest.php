<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\AcceptProviderCode;
use App\Services\AuditProviderCodes;
use App\Services\DeliverOrderItem;
use App\Services\Discrepancies;
use App\Services\ProviderStub;
use App\Services\Reconciliation;
use Tests\TestCase;

/**
 * Поставщик, которому нельзя доверять.
 *
 * Он может выдать один код дважды, прислать чужой код или ответить ошибкой,
 * выдав код на самом деле. Ответу верить нельзя, поэтому гарантии строятся
 * на нашей стороне: приёмка кода и сверка с состоянием поставщика.
 */
final class DishonestProviderTest extends TestCase
{
    private function behavior(string $provider, string $mode): void
    {
        $this->container->get(ProviderStub::class)->configure($provider, $mode, 0.0, 0.0, 1);
    }

    /** Заказ из одной позиции, оплаченный и доведённый до завершения. */
    private function settledOrder(): string
    {
        $order = $this->createOrder();
        $orderId = $order['order_id'];

        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($orderId));
        $this->runWorker();

        $deliver = $this->container->get(DeliverOrderItem::class);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (in_array($this->itemStatuses($orderId)[1], [OrderItem::DELIVERED, OrderItem::REFUNDED], true)) {
                break;
            }

            $deliver($orderId, 1);
        }

        return $orderId;
    }

    private function codeOf(string $orderId): ?string
    {
        $row = $this->db->selectOne(
            "SELECT code FROM deliveries WHERE order_id = ? AND position = 1 AND status = 'delivered'",
            [$orderId]
        );

        return $row === null ? null : (string) $row['code'];
    }

    private function providerOf(string $orderId): ?string
    {
        $row = $this->db->selectOne(
            'SELECT provider FROM deliveries WHERE order_id = ? AND position = 1',
            [$orderId]
        );

        return $row === null ? null : (string) $row['provider'];
    }

    public function testДубльНеПопадаетВоВторойЗаказ(): void
    {
        $this->behavior('a', ProviderStub::OK);
        $this->behavior('b', ProviderStub::ERROR);

        $first = $this->settledOrder();
        $firstCode = $this->codeOf($first);

        // Теперь A присылает всем один и тот же код, B работает резервным.
        $this->behavior('a', ProviderStub::DUPLICATE_CODE);
        $this->behavior('b', ProviderStub::OK);

        $second = $this->settledOrder();

        self::assertNotNull($firstCode);
        self::assertNotSame($firstCode, $this->codeOf($second), 'код не ушёл во второй заказ');
        self::assertSame('b', $this->providerOf($second), 'выдал резервный поставщик');
        self::assertSame(1, $this->rows('issued_codes', "code = '$firstCode'"));
        self::assertSame(2, $this->rows('issued_codes'), 'по коду на заказ');
    }

    public function testЧужойКодОтклоняется(): void
    {
        // Первый заказ обслуживает B: его код становится принятым.
        $this->behavior('a', ProviderStub::ERROR);
        $this->behavior('b', ProviderStub::OK);

        $first = $this->settledOrder();
        $firstCode = (string) $this->codeOf($first);

        // A отдаёт код, которым не распоряжается, — уже принятый первым заказом.
        $this->behavior('a', ProviderStub::FOREIGN_CODE);

        $second = $this->settledOrder();

        self::assertNotSame($firstCode, $this->codeOf($second));
        self::assertSame(
            $first,
            (string) $this->db->selectOne('SELECT order_id FROM issued_codes WHERE code = ?', [$firstCode])['order_id'],
            'код остался у первого заказа'
        );
    }

    public function testОшибкаПриВыданномКодеРазбираетсяСразу(): void
    {
        $this->behavior('a', ProviderStub::ERROR_BUT_ISSUED);
        $this->behavior('b', ProviderStub::ERROR);

        $orderId = $this->settledOrder();

        self::assertSame(Order::DELIVERED, $this->orderStatus($orderId), 'код найден проверкой статуса');
        self::assertSame('a', $this->providerOf($orderId));
        self::assertSame(1, $this->rows('issued_codes', "order_id = '$orderId'"));
        self::assertSame(1, $this->rows('provider_stock', "request_id LIKE '%$orderId%'"),
            'у поставщика занят ровно один код');
    }

    public function testМолчаниеРазрешаетсяЗапросомСостояния(): void
    {
        // Поставщик выдал код и не ответил, но на запрос состояния отвечает.
        $this->behavior('a', ProviderStub::ISSUE_THEN_TIMEOUT);
        $this->behavior('b', ProviderStub::OK);

        $orderId = $this->settledOrder();

        self::assertSame(Order::DELIVERED, $this->orderStatus($orderId));
        self::assertSame('a', $this->providerOf($orderId), 'на резервного не переключились');
        self::assertSame(1, $this->rows('provider_stock', "request_id LIKE '%$orderId%'"),
            'второго кода не занято');
        self::assertSame(0, $this->rows('provider_discrepancies', 'resolved_at IS NULL'));
    }

    public function testПолнаяНедоступностьОставляетНеопределённость(): void
    {
        // Ни выдачи, ни состояния: узнать правду не у кого.
        $this->behavior('a', ProviderStub::BLACKOUT);
        $this->behavior('b', ProviderStub::OK);

        $order = $this->createOrder();
        $orderId = $order['order_id'];
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($orderId));
        $this->runWorker();

        self::assertSame(OrderItem::UNRESOLVED, $this->itemStatuses($orderId)[1]);
        self::assertSame(0, $this->rows('issued_codes'), 'непроверенный код не принят');
        self::assertSame(0, $this->rows('provider_stock', "provider = 'b' AND request_id LIKE '%$orderId%'"),
            'на резервного не переключились');
    }

    public function testПовторПослеОшибкиНеСоздаётВторойВыдачи(): void
    {
        $this->behavior('a', ProviderStub::ERROR_BUT_ISSUED);
        $this->behavior('b', ProviderStub::ERROR);

        $orderId = $this->settledOrder();
        $code = $this->codeOf($orderId);

        // Три повтора по завершённой позиции.
        $deliver = $this->container->get(DeliverOrderItem::class);
        $deliver($orderId, 1);
        $deliver($orderId, 1);
        $deliver($orderId, 1);

        self::assertSame($code, $this->codeOf($orderId));
        self::assertSame(1, $this->rows('issued_codes', "order_id = '$orderId'"));
        self::assertSame(1, $this->rows('provider_stock', "request_id LIKE '%$orderId%'"));
    }

    public function testРасхожденияФиксируютсяИЗакрываются(): void
    {
        $this->behavior('a', ProviderStub::ERROR_BUT_ISSUED);
        $this->behavior('b', ProviderStub::ERROR);

        $orderId = $this->settledOrder();

        $kinds = array_column(
            $this->db->select('SELECT kind, resolved_at FROM provider_discrepancies WHERE order_id = ?', [$orderId]),
            'kind'
        );

        self::assertContains(Discrepancies::SILENT_ISSUE, $kinds);
        self::assertSame(0, $this->rows('provider_discrepancies', 'resolved_at IS NULL'),
            'разбор прошёл без вмешательства');
    }

    public function testСверкаНаходитКодПослеОбрыва(): void
    {
        $this->behavior('a', ProviderStub::OK);
        $this->behavior('b', ProviderStub::ERROR);

        $order = $this->createOrder();
        $orderId = $order['order_id'];
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($orderId));

        $requestId = OrderItem::requestIdFor($orderId, 1, 'a');

        // Поставщик занял код по нашему запросу, ответ не дошёл, процесс погиб.
        $this->db->execute(
            'UPDATE provider_stock SET request_id = ?, issued_at = now()
              WHERE id = (SELECT id FROM provider_stock
                           WHERE provider = ? AND sku = ? AND request_id IS NULL
                           ORDER BY id LIMIT 1)',
            [$requestId, 'a', 'KEY-GTA5']
        );
        $this->db->execute(
            "INSERT INTO delivery_attempts
                    (order_id, position, provider, request_id, attempt_no, generation, outcome, reason)
             VALUES (?, 1, 'a', ?, 1, 1, 'unknown', 'timeout')",
            [$orderId, $requestId]
        );
        $this->db->execute(
            'UPDATE order_items SET status = ? WHERE order_id = ? AND position = 1',
            [OrderItem::UNRESOLVED, $orderId]
        );
        $this->db->execute('DELETE FROM jobs');

        $claimed = (string) $this->db->selectOne(
            'SELECT code FROM provider_stock WHERE request_id = ?',
            [$requestId]
        )['code'];

        $audited = ($this->container->get(AuditProviderCodes::class))();
        $this->runWorker();

        self::assertSame(1, $audited['recovered'], 'сверка нашла выданный код');
        self::assertSame($claimed, $this->codeOf($orderId), 'выдан ранее занятый код');
        self::assertSame(Order::DELIVERED, $this->orderStatus($orderId));
        self::assertSame(1, $this->rows('provider_stock', "request_id LIKE '%$orderId%'"),
            'второго кода не занято');
    }

    public function testПовторнаяСверкаНичегоНеМеняет(): void
    {
        $this->behavior('a', ProviderStub::ERROR_BUT_ISSUED);
        $this->behavior('b', ProviderStub::ERROR);

        $orderId = $this->settledOrder();
        $before = [$this->codeOf($orderId), $this->rows('issued_codes'), $this->rows('ledger_entries')];

        $audit = $this->container->get(AuditProviderCodes::class);
        $audit();
        $audit();

        self::assertSame(
            $before,
            [$this->codeOf($orderId), $this->rows('issued_codes'), $this->rows('ledger_entries')]
        );
    }

    public function testПриёмкаРазличаетПовторИКонфликт(): void
    {
        $orderId = $this->settledOrder();
        $code = (string) $this->codeOf($orderId);

        $accept = $this->container->get(AcceptProviderCode::class);
        $requestId = OrderItem::requestIdFor($orderId, 1, 'a');

        // Тот же код по тому же запросу — обычный повтор.
        $repeat = $accept('a', $requestId, $orderId, 1, $code);
        self::assertSame(AcceptProviderCode::REPEAT, $repeat['outcome']);
        self::assertSame($code, $repeat['code']);

        // Другой код по тому же запросу — принятым остаётся первый.
        $conflict = $accept('a', $requestId, $orderId, 1, 'ПОДМЕНА-0001');
        self::assertSame(AcceptProviderCode::CONFLICT, $conflict['outcome']);
        self::assertSame($code, $conflict['code'], 'подменённый код не принят');
        self::assertSame(0, $this->rows('issued_codes', "code = 'ПОДМЕНА-0001'"));
    }

    public function testОтчётСверкиПоказываетРасхождения(): void
    {
        $this->behavior('a', ProviderStub::ERROR_BUT_ISSUED);
        $this->behavior('b', ProviderStub::ERROR);

        $this->settledOrder();

        $report = $this->container->get(Reconciliation::class)->report();

        self::assertArrayHasKey(Discrepancies::SILENT_ISSUE, $report['discrepancies']);
        self::assertSame(0, $report['summary']['open_discrepancies']);
        self::assertSame(0, $report['summary']['unaccepted_codes'], 'кодов в обход приёмки нет');
        self::assertTrue($report['healthy']);
    }
}
