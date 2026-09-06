<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\Reconciliation;

final class ReconciliationController
{
    public function __construct(private readonly Reconciliation $reconciliation)
    {
    }

    public function show(Request $request): Response
    {
        $report = $this->reconciliation->report();

        // Код состояния отражает результат: наличие расхождений видно
        // системе мониторинга без разбора тела ответа.
        return Response::json($report, $report['healthy'] ? 200 : 409);
    }
}
