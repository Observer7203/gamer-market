<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Контракт обращения к поставщику выдачи.
 *
 * Отделён от реализации, потому что вызов уходит за пределы процесса:
 * в тестах он подменяется локальным исполнением, иначе заглушка отвечала бы
 * из другого процесса с другим подключением к базе.
 */
interface ProviderClient
{
    public const OK      = 'ok';
    public const ERROR   = 'error';
    public const UNKNOWN = 'unknown';

    /**
     * Исход unknown означает отсутствие ответа. Он не равнозначен отказу:
     * поставщик мог выдать код, а ответ не дойти. Различение этих случаев —
     * условие того, что повтор не приведёт к повторной выдаче.
     *
     * @return array{outcome: string, code: ?string, reason: ?string, http: int, latency_ms: int}
     */
    public function issue(string $provider, string $requestId, string $sku, string $orderId): array;

    /**
     * Что поставщик считает выданным по этому запросу.
     *
     * Единственный способ узнать правду после отказа или молчания: ответу
     * на выдачу верить нельзя, а состояние на стороне поставщика проверяемо.
     * На этом построен автоматический разбор расхождений.
     *
     * Исход unknown означает, что и на этот запрос ответа не было —
     * состояние по-прежнему неизвестно.
     *
     * @return array{outcome: string, code: ?string, reason: ?string}
     */
    public function status(string $provider, string $requestId): array;
}
