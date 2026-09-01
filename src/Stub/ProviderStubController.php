<?php

declare(strict_types=1);

namespace App\Stub;

use App\Http\Request;
use App\Http\Response;

final class ProviderStubController
{
    public function __construct(private readonly ProviderStub $stub)
    {
    }

    public function issue(Request $request): Response
    {
        $provider = $request->attribute('provider');
        $requestId = $request->input('request_id');
        $sku = $request->input('sku');

        if (!is_string($requestId) || $requestId === '' || !is_string($sku) || $sku === '') {
            return Response::error('invalid_request', 'Поля request_id и sku обязательны', 400);
        }

        $result = $this->stub->issue($provider, $requestId, $sku);

        if ($result['status'] === 'ok') {
            return Response::json([
                'status'     => 'ok',
                'request_id' => $requestId,
                'code'       => $result['code'],
            ]);
        }

        return Response::json(
            ['status' => 'error', 'reason' => $result['reason']],
            $result['reason'] === 'out_of_stock' ? 409 : 500
        );
    }
}
