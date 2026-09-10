<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Queue\Queue;
use App\Services\DeliverOrderItem;
use App\Services\ProviderRateLimiter;
use App\Services\QueueProgress;
use Tests\TestCase;

/**
 * Всплеск заказов при ограниченном поставщике.
 *
 * Терять заказы нельзя и превышать лимит нельзя. При исчерпании лимита работа
 * откладывается, а не проваливается; оплаченные обслуживаются раньше.
 */
final class RateLimitTest extends TestCase
{
    private function limiter(): ProviderRateLimiter
    {
        return $this->container->get(ProviderRateLimiter::class);
    }

    private function limitBoth(int $perMinute): void
    {
        foreach (['a', 'b'] as $provider) {
            $this->limiter()->configure($provider, $perMinute);
        }
    }

    private function paidOrder(): string
    {
        $order = $this->createOrder();
        $this->request('POST', '/api/webhooks/payment', $this->paymentEvent($order['order_id']));

        return $order['order_id'];
    }

    public function testЛимитНеПревышается(): void
    {
        $this->limitBoth(2);

        for ($i = 0; $i < 6; $i++) {
            self::assertSame($i < 4, $this->limiter()->acquire($i < 2 ? 'a' : 'b'), "обращение $i");
        }

        self::assertSame(['a' => 2, 'b' => 2], $this->limiter()->peak());
    }

    public function testЛимитПоставщиковНезависим(): void
    {
        $this->limiter()->configure('a', 1);
        $this->limiter()->configure('b', 3);

        self::assertTrue($this->limiter()->acquire('a'));
        self::assertFalse($this->limiter()->acquire('a'), 'у a место кончилось');
        self::assertTrue($this->limiter()->acquire('b'), 'у b свой лимит');
    }

    public function testОкноСкользящееАНеКалендарное(): void
    {
        $this->limiter()->configure('a', 2, windowSeconds: 60);

        self::assertTrue($this->limiter()->acquire('a'));
        self::assertTrue($this->limiter()->acquire('a'));
        self::assertFalse($this->limiter()->acquire('a'));

        // Обращения уходят за окно — место освобождается.
        $this->db->execute("UPDATE provider_calls SET called_at = now() - interval '2 minutes'");

        self::assertTrue($this->limiter()->acquire('a'));
    }

    public function testИсчерпанныйЛимитОткладываетРаботуАНеТеряет(): void
    {
        // По одному месту у каждого поставщика: первые две позиции их займут,
        // третьей места не останется.
        $this->limitBoth(1);

        $first = $this->paidOrder();
        $secondOrder = $this->paidOrder();
        $second = $this->paidOrder();

        $deliver = $this->container->get(DeliverOrderItem::class);

        self::assertSame('delivered', $deliver($first, 1), 'место у a');
        self::assertSame('delivered', $deliver($secondOrder, 1), 'место у b');
        self::assertSame(DeliverOrderItem::THROTTLED, $deliver($second, 1));

        // Позиция не тронута: ни попытки, ни смены состояния.
        self::assertSame(OrderItem::PENDING, $this->itemStatuses($second)[1]);
        self::assertSame(0, $this->rows('delivery_attempts', "order_id = '$second'"));
        self::assertSame(0, (int) $this->db->selectOne(
            'SELECT coalesce(max(attempts), 0) AS n FROM deliveries WHERE order_id = ?',
            [$second]
        )['n']);

        // Место освободилось — позиция выдана без потерь.
        $this->db->execute("UPDATE provider_calls SET called_at = now() - interval '2 minutes'");

        self::assertSame('delivered', $deliver($second, 1));
        self::assertSame(Order::DELIVERED, $this->orderStatus($second));
    }

    public function testОтложеннаяЗадачаНеТратитПопытку(): void
    {
        // Оба места уходят на первые две позиции, третьей не достаётся.
        $this->limitBoth(1);
        $this->paidOrder();
        $this->paidOrder();
        $second = $this->paidOrder();

        $this->runWorker();

        $job = $this->db->selectOne(
            "SELECT * FROM jobs WHERE payload->>'order_id' = ? ORDER BY id DESC LIMIT 1",
            [$second]
        );

        self::assertSame('pending', $job['status'], 'задача вернулась в очередь');
        self::assertSame('rate_limited', $job['last_error']);
        self::assertSame(0, (int) $job['attempts'], 'попытка не засчитана');
    }

    public function testОплаченныеОбслуживаютсяРаньше(): void
    {
        $queue = $this->container->get(Queue::class);

        $queue->push('deliver_item', ['order_id' => 'ord_НЕОПЛАЧЕН', 'position' => 1],
            priority: Queue::UNPAID_DELIVERY);

        $paid = $this->paidOrder();

        $claimed = $queue->claim();

        self::assertSame($paid, json_decode((string) $claimed['payload'], true)['order_id'],
            'оплаченный заказ взят первым, хотя поставлен позже');
        self::assertSame(Queue::PAID_DELIVERY, (int) $claimed['priority']);
    }

    public function testПрогрессВиден(): void
    {
        $this->limitBoth(10);
        $this->paidOrder();
        $this->paidOrder();

        $snapshot = $this->container->get(QueueProgress::class)->snapshot();

        self::assertSame(2, $snapshot['queue']['pending']);
        self::assertSame(2, $snapshot['queue']['paid_ahead']);
        self::assertSame(2, $snapshot['items']['awaiting']);
        self::assertSame(10, $snapshot['providers']['a']['limit_per_minute']);
        self::assertSame(20, $snapshot['throughput']['limit_per_minute'], 'сумма лимитов поставщиков');
        self::assertSame(6, $snapshot['throughput']['eta_seconds'], '2 позиции при 20 в минуту');

        $this->runWorker();

        $after = $this->container->get(QueueProgress::class)->snapshot();

        self::assertSame(0, $after['queue']['pending']);
        self::assertSame(2, $after['items']['delivered']);
    }

    public function testПрогрессОтдаётсяПоHttp(): void
    {
        $this->limitBoth(10);
        $this->paidOrder();

        $response = $this->request('GET', '/api/admin/queue');

        self::assertSame(200, $response->status);
        self::assertSame(1, $response->body['queue']['pending']);
        self::assertArrayHasKey('a', $response->body['providers']);
        self::assertArrayHasKey('eta_seconds', $response->body['throughput']);
    }

    public function testВыполненныеЗадачиУдаляются(): void
    {
        $this->limitBoth(10);
        $this->paidOrder();
        $this->runWorker();

        self::assertSame(1, $this->rows('jobs', "status = 'done'"));

        $heal = $this->container->get(\App\Services\HealStuckOrders::class);

        // Свежая задача остаётся: удаляются только старше суток.
        self::assertSame(0, $heal()['pruned_jobs']);

        $this->db->execute("UPDATE jobs SET locked_at = now() - interval '2 days'");

        self::assertSame(1, $heal()['pruned_jobs']);
        self::assertSame(0, $this->rows('jobs'));
    }

    public function testПроваленныеЗадачиНеУдаляются(): void
    {
        $this->limitBoth(10);
        $this->paidOrder();
        $this->runWorker();

        $this->db->execute(
            "UPDATE jobs SET status = 'failed', locked_at = now() - interval '2 days'"
        );

        self::assertSame(0, $this->container->get(\App\Services\HealStuckOrders::class)()['pruned_jobs'],
            'проваленные требуют разбора');
        self::assertSame(1, $this->rows('jobs'));
    }

    public function testНеоплаченныеПозицииНеСчитаютсяОжидающими(): void
    {
        $this->limitBoth(10);
        $this->paidOrder();
        $this->createOrder();

        $items = $this->container->get(QueueProgress::class)->snapshot()['items'];

        self::assertSame(1, $items['awaiting'], 'ждёт тот, кто заплатил');
        self::assertSame(1, $items['unpaid']);
    }

    public function testОчисткаЖурналаОбращений(): void
    {
        $this->limitBoth(10);
        $this->limiter()->acquire('a');
        $this->db->execute("UPDATE provider_calls SET called_at = now() - interval '2 hours'");
        $this->limiter()->acquire('a');

        self::assertSame(1, $this->limiter()->prune(3600), 'старое обращение удалено');
        self::assertSame(1, $this->rows('provider_calls'));
    }
}
