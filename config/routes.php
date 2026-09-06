<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\OrderController;
use App\Controllers\PaymentWebhookController;
use App\Controllers\ReconciliationController;
use App\Http\Router;
use App\Controllers\ProviderStubController;

/**
 * Карта маршрутов: полный перечень адресов, доступных извне.
 */
return static function (Router $router): void {

    $router->get('/health', [HealthController::class, 'show']);

    $router->post('/api/orders', [OrderController::class, 'store']);
    $router->get('/api/orders/{id}', [OrderController::class, 'show']);

    $router->post('/api/webhooks/payment', [PaymentWebhookController::class, 'store']);

    // Сверка: расхождения между полученными деньгами и выданным товаром.
    $router->get('/api/admin/reconciliation', [ReconciliationController::class, 'show']);

    // Заглушка поставщика: отдельный сервис, размещённый в том же приложении
    // ради простоты запуска.
    $router->post('/stubs/provider-{provider}/issue', [ProviderStubController::class, 'issue']);

    // Служебный маршрут: задаёт поведение заглушки для воспроизводимых
    // сценариев отказа и неответа.
    $router->post('/stubs/provider-{provider}/behavior', [ProviderStubController::class, 'behavior']);
};
