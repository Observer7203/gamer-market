<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\Catalog;

final class CatalogController
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function show(Request $request): Response
    {
        $page = $this->catalog->showcase([
            'type'     => $request->input('type'),
            'in_stock' => (bool) $request->input('in_stock'),
            'after'    => $request->input('after'),
            'limit'    => $request->input('limit') === null ? null : (int) $request->input('limit'),
        ]);

        return Response::json($page);
    }
}
