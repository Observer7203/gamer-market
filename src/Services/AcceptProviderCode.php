<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Support\Logger;

/**
 * Приёмка кода от поставщика.
 *
 * Ответ поставщика — заявление, а не факт. Он может выдать один код дважды,
 * прислать чужой код или ответить отказом, выдав код на самом деле. Поэтому
 * между «поставщик прислал код» и «код принадлежит заказу» стоит приёмка.
 *
 * Решение принимают ограничения таблицы issued_codes, а не проверки здесь:
 *
 *   PRIMARY KEY (code)                один код физически не ляжет в два заказа
 *   UNIQUE (provider, request_id)     один запрос физически не даст двух кодов
 *
 * Проверка «а нет ли уже такого кода» перед вставкой не дала бы ничего:
 * между чтением и записью вклинивается конкурент. Здесь же вставка либо
 * проходит, либо нарушает ограничение, и второй случай разбирается.
 */
final class AcceptProviderCode
{
    /** Код принят: до этого он никому не принадлежал. */
    public const ACCEPTED = 'accepted';

    /** Тот же код по тому же запросу: повтор, ничего не изменилось. */
    public const REPEAT = 'repeat';

    /** Код уже принадлежит другой позиции. Поставщик выдал его дважды. */
    public const DUPLICATE = 'duplicate';

    /** По тому же запросу поставщик прислал другой код. Принятым остаётся первый. */
    public const CONFLICT = 'conflict';

    public function __construct(
        private readonly Connection $db,
        private readonly Discrepancies $discrepancies,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{outcome: string, code: string} принятый код может отличаться
     *         от присланного: при повторе им остаётся принятый ранее
     */
    public function __invoke(
        string $provider,
        string $requestId,
        string $orderId,
        int $position,
        string $code
    ): array {
        // ON CONFLICT, а не перехват исключения: нарушение ограничения
        // прервало бы всю транзакцию выдачи, и следующие операторы в ней
        // уже не выполнились бы.
        $inserted = $this->db->execute(
            'INSERT INTO issued_codes (code, provider, request_id, order_id, position)
                  VALUES (?, ?, ?, ?, ?)
             ON CONFLICT DO NOTHING',
            [$code, $provider, $requestId, $orderId, $position]
        );

        if ($inserted === 1) {
            $this->logger->info('code_accepted', [
                'channel'    => 'delivery',
                'order_id'   => $orderId,
                'position'   => $position,
                'provider'   => $provider,
                'request_id' => $requestId,
            ]);

            return ['outcome' => self::ACCEPTED, 'code' => $code];
        }

        return $this->classify($provider, $requestId, $orderId, $position, $code);
    }

    /**
     * Разбор отклонённой вставки.
     *
     * Ограничение уже сработало — остаётся выяснить, какое именно, и записать
     * расхождение, если поставщик повёл себя недобросовестно.
     *
     * @return array{outcome: string, code: string}
     */
    private function classify(
        string $provider,
        string $requestId,
        string $orderId,
        int $position,
        string $code
    ): array {
        $owner = $this->db->selectOne(
            'SELECT order_id, position, provider, request_id FROM issued_codes WHERE code = ?',
            [$code]
        );

        // Тот же код по тому же запросу той же позиции: обычный повтор.
        if ($owner !== null
            && (string) $owner['order_id'] === $orderId
            && (int) $owner['position'] === $position
            && (string) $owner['request_id'] === $requestId) {
            return ['outcome' => self::REPEAT, 'code' => $code];
        }

        // По этому запросу код уже принят, но поставщик прислал другой.
        $accepted = $this->db->selectOne(
            'SELECT code FROM issued_codes WHERE provider = ? AND request_id = ?',
            [$provider, $requestId]
        );

        if ($accepted !== null && (string) $accepted['code'] !== $code) {
            $this->discrepancies->record(
                Discrepancies::REQUEST_CONFLICT,
                $provider,
                $requestId,
                $orderId,
                $position,
                $code,
                sprintf('по запросу уже принят код %s', $accepted['code']),
            );

            $this->logger->error('code_conflict', [
                'channel'       => 'delivery',
                'order_id'      => $orderId,
                'position'      => $position,
                'provider'      => $provider,
                'request_id'    => $requestId,
                'accepted_code' => (string) $accepted['code'],
            ]);

            // Принятым остаётся первый код: он уже мог уйти покупателю.
            return ['outcome' => self::CONFLICT, 'code' => (string) $accepted['code']];
        }

        // Код принадлежит другой позиции: поставщик выдал его дважды
        // либо прислал чужой.
        $this->discrepancies->record(
            Discrepancies::DUPLICATE_CODE,
            $provider,
            $requestId,
            $orderId,
            $position,
            $code,
            $owner === null
                ? 'код отклонён приёмкой'
                : sprintf('код принадлежит %s позиции %d', $owner['order_id'], $owner['position']),
        );

        $this->logger->error('code_duplicate', [
            'channel'    => 'delivery',
            'order_id'   => $orderId,
            'position'   => $position,
            'provider'   => $provider,
            'request_id' => $requestId,
            'owner'      => $owner === null ? null : (string) $owner['order_id'],
        ]);

        return ['outcome' => self::DUPLICATE, 'code' => $code];
    }
}
