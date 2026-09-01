<?php

declare(strict_types=1);

use App\Http\Controller\HealthController;
use App\Http\Router;

/**
 * Карта маршрутов: полный перечень адресов, доступных извне.
 */
return static function (Router $router): void {

    $router->get('/health', [HealthController::class, 'show']);

    // Ядро API
    // POST /api/orders                создание заказа по SKU
    // GET  /api/orders/{id}           состояние заказа и выданный код
    // POST /api/webhooks/payment      вебхук платёжной системы

    // Заглушки поставщиков
    // POST /stubs/provider-a/issue
    // POST /stubs/provider-b/issue

    // Сверка
    // GET  /api/admin/reconciliation
};
