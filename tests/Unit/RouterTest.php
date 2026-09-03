<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->get('/api/orders/{id}', ['OrderController', 'show']);
        $router->post('/api/orders', ['OrderController', 'store']);
        $router->post('/stubs/provider-{provider}/issue', ['ProviderStubController', 'issue']);

        return $router;
    }

    public function testСовпадениеБезПараметров(): void
    {
        $route = $this->router()->match('POST', '/api/orders');

        self::assertNotNull($route);
        self::assertSame(['OrderController', 'store'], $route['handler']);
        self::assertSame([], $route['attributes']);
    }

    public function testИзвлечениеПараметраПути(): void
    {
        $route = $this->router()->match('GET', '/api/orders/ord_01ABC');

        self::assertNotNull($route);
        self::assertSame(['id' => 'ord_01ABC'], $route['attributes']);
    }

    public function testНесколькоПлейсхолдеров(): void
    {
        $route = $this->router()->match('POST', '/stubs/provider-b/issue');

        self::assertNotNull($route);
        self::assertSame(['provider' => 'b'], $route['attributes']);
    }

    public function testМетодУчитывается(): void
    {
        self::assertNull($this->router()->match('GET', '/api/orders'));
    }

    public function testНеизвестныйПуть(): void
    {
        self::assertNull($this->router()->match('GET', '/nope'));
    }

    public function testПлейсхолдерНеЗахватываетСлеш(): void
    {
        self::assertNull($this->router()->match('GET', '/api/orders/ord_1/extra'));
    }
}
