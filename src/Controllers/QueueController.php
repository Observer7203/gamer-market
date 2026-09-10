<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\QueueProgress;

final class QueueController
{
    public function __construct(private readonly QueueProgress $progress)
    {
    }

    public function show(Request $request): Response
    {
        return Response::json($this->progress->snapshot());
    }
}
