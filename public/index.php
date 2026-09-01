<?php

declare(strict_types=1);

use App\Http\Dispatcher;
use App\Http\Request;
use App\Support\Container;

/**
 * Точка входа HTTP. Единственный файл в document root; остальной код
 * недоступен по сети.
 */

/** @var Container $container */
$container = require dirname(__DIR__) . '/src/bootstrap.php';

$container->get(Dispatcher::class)
    ->handle(Request::fromGlobals())
    ->send();
