<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\TemporalState;
use App\Support\Money;

/**
 * Восстановление картины на прошлый момент.
 *
 * Отдельный контроллер, а не параметр к обычному чтению заказа: вопрос
 * «что было тогда» задаёт не покупатель, а разбор — бухгалтерия, поддержка,
 * спор с поставщиком.
 */
final class HistoryController
{
    public function __construct(private readonly TemporalState $state)
    {
    }

    public function order(Request $request): Response
    {
        $at = $this->moment($request);

        if ($at === null) {
            return Response::error('invalid_request', 'Параметр at должен быть временем в формате ISO 8601', 400);
        }

        $state = $this->state->orderAt($request->attribute('id'), $at);

        if ($state === null) {
            return Response::error('order_not_found', 'На указанный момент заказа ещё не существовало', 404);
        }

        return Response::json([
            'order_id' => $state['order_id'],
            'at'       => $state['at'],
            'status'   => $state['status'],
            'amount'   => Money::toContract($state['amount_minor']),
            'items'    => array_map(
                static fn (array $item): array => [
                    'position'   => $item['position'],
                    'status'     => $item['status'],
                    'amount'     => Money::toContract($item['amount_minor']),
                    'changed_at' => $item['changed_at'],
                    'code'       => $item['code'],
                ],
                $state['items'],
            ),
            'money'    => $this->present($state['money']),
            'timeline' => $this->state->timeline($state['order_id'], $at),
        ]);
    }

    public function money(Request $request): Response
    {
        $at = $this->moment($request);

        if ($at === null) {
            return Response::error('invalid_request', 'Параметр at должен быть временем в формате ISO 8601', 400);
        }

        return Response::json($this->present($this->state->moneyAt($at)));
    }

    public function period(Request $request): Response
    {
        $from = $this->parse($request->input('from'));
        $to = $this->parse($request->input('to'));

        if ($from === null || $to === null) {
            return Response::error('invalid_request', 'Параметры from и to обязательны', 400);
        }

        if ($from > $to) {
            return Response::error('invalid_request', 'Начало периода позже конца', 400);
        }

        $totals = $this->state->periodTotals($from, $to);

        return Response::json([
            'from'              => $totals['from'],
            'to'                => $totals['to'],
            'orders'            => $totals['orders'],
            'opening'           => $this->present($totals['opening']),
            'movement'          => $this->present($totals['movement']),
            'closing'           => $this->present($totals['closing']),
            'movement_balanced' => $totals['movement_balanced'],
            'consistent'        => $totals['consistent'],
        ]);
    }

    /** Момент по умолчанию — сейчас: запрос без параметра отдаёт текущую картину. */
    private function moment(Request $request): ?string
    {
        $at = $request->input('at');

        return $at === null ? $this->parse('now') : $this->parse($at);
    }

    /**
     * Разбор момента с сохранением долей секунды.
     *
     * Округление до секунды сдвинуло бы границу: внутри одной секунды заказ
     * успевает пройти несколько состояний, и картина получилась бы не того
     * момента, о котором спрашивали.
     */
    private function parse(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d\TH:i:s.uP');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $money
     * @return array<string, mixed>
     */
    private function present(array $money): array
    {
        return [
            'paid'        => Money::toContract((int) $money['paid_minor']),
            'delivered'   => Money::toContract((int) $money['delivered_minor']),
            'refunded'    => Money::toContract((int) $money['refunded_minor']),
            'in_progress' => Money::toContract((int) $money['in_progress_minor']),
            'balanced'    => $money['balanced'],
            'identity'    => 'оплачено = выдано + возвращено + в работе',
        ];
    }
}
