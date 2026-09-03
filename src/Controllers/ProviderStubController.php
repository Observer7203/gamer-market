<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\ProviderStub;

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

    /**
     * Управление поведением заглушки.
     *
     * Служебный маршрут: позволяет воспроизводить отказы и неответы
     * детерминированно, не изменяя код и не перезапуская окружение.
     */
    public function behavior(Request $request): Response
    {
        $provider = $request->attribute('provider');
        $mode = $request->input('mode', ProviderStub::RANDOM);

        $allowed = [
            ProviderStub::RANDOM, ProviderStub::OK, ProviderStub::ERROR,
            ProviderStub::OUT_OF_STOCK, ProviderStub::TIMEOUT, ProviderStub::ISSUE_THEN_TIMEOUT,
        ];

        if (!in_array($mode, $allowed, true)) {
            return Response::error('invalid_request', 'Допустимые режимы: ' . implode(', ', $allowed), 400);
        }

        $this->stub->configure(
            $provider,
            (string) $mode,
            (float) $request->input('fail_rate', 0),
            (float) $request->input('timeout_rate', 0),
            (int) $request->input('hang_seconds', 5),
        );

        return Response::json([
            'provider'     => $provider,
            'mode'         => $mode,
            'fail_rate'    => (float) $request->input('fail_rate', 0),
            'timeout_rate' => (float) $request->input('timeout_rate', 0),
            'hang_seconds' => (int) $request->input('hang_seconds', 5),
        ]);
    }
}
