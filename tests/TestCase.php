<?php

declare(strict_types=1);

namespace Tests;

use App\Database\Connection;
use App\Http\Dispatcher;
use App\Http\Request;
use App\Http\Response;
use App\Services\ProviderClient;
use App\Queue\Worker;
use Tests\Support\LocalProviderClient;
use App\Support\Container;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Общая основа тестов: чистая база и минимальный каталог перед каждым методом.
 */
abstract class TestCase extends BaseTestCase
{
    protected Container $container;
    protected Connection $db;

    protected function setUp(): void
    {
        $this->container = require dirname(__DIR__) . '/src/bootstrap.php';
        $this->db = $this->container->get(Connection::class);

        // Заглушка вызывается внутри процесса: по HTTP запрос ушёл бы
        // в другой контейнер и работал бы с рабочей базой.
        $this->container->singleton(
            ProviderClient::class,
            fn (Container $c): ProviderClient => new LocalProviderClient($c->get(\App\Services\ProviderStub::class))
        );

        $this->db->raw(
            'TRUNCATE delivery_attempts, deliveries, jobs, payment_events, orders,
                      provider_stock, provider_settings, products
             RESTART IDENTITY CASCADE'
        );

        $this->seedCatalog();
    }

    /** Один товар за 1990 и по несколько кодов на складе каждого поставщика. */
    protected function seedCatalog(int $codes = 3): void
    {
        $this->db->execute(
            'INSERT INTO products (sku, name, type, price_minor, currency) VALUES (?, ?, ?, ?, ?)',
            ['KEY-GTA5', 'GTA V ключ активации', 'key', 199000, 'RUB']
        );

        foreach (['a', 'b'] as $provider) {
            for ($i = 1; $i <= $codes; $i++) {
                $this->db->execute(
                    'INSERT INTO provider_stock (provider, sku, code) VALUES (?, ?, ?)',
                    [$provider, 'KEY-GTA5', sprintf('%s-TEST-%04d', $provider, $i)]
                );
            }

            $this->db->execute(
                'INSERT INTO provider_settings (provider, mode) VALUES (?, ?)',
                [$provider, 'ok']
            );
        }
    }

    /** @param array<string, mixed> $json */
    protected function request(string $method, string $path, array $json = []): Response
    {
        return $this->container->get(Dispatcher::class)->handle(new Request($method, $path, $json));
    }

    /** @return array<string, mixed> */
    protected function createOrder(string $sku = 'KEY-GTA5'): array
    {
        return (array) $this->request('POST', '/api/orders', ['sku' => $sku])->body;
    }

    /**
     * Событие платёжной системы по контракту.
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    protected function paymentEvent(string $orderId, array $override = []): array
    {
        // Порядок важен: оператор + оставляет значение левого массива,
        // поэтому переопределения идут первыми.
        return $override + [
            'event_id'   => 'evt_' . bin2hex(random_bytes(4)),
            'order_id'   => $orderId,
            'status'     => 'paid',
            'amount'     => 1990,
            'currency'   => 'RUB',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** Обрабатывает очередь до опустошения. */
    protected function runWorker(int $limit = 10): void
    {
        $worker = $this->container->get(Worker::class);

        while ($limit-- > 0 && $worker->tick()) {
            // задачи обрабатываются по одной
        }
    }

    protected function orderStatus(string $orderId): ?string
    {
        $row = $this->db->selectOne('SELECT status FROM orders WHERE id = ?', [$orderId]);

        return $row === null ? null : (string) $row['status'];
    }

    protected function rows(string $table, string $where = 'true'): int
    {
        return (int) $this->db->selectOne("SELECT count(*) AS n FROM $table WHERE $where")['n'];
    }
}
