<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Keeta;




/**
 * Fake Keeta client: records lifecycle calls + the last menu push so CI can assert the
 * full-replace payload and status-back matrix without touching the network.
 */
class FakeKeetaClient implements KeetaClientInterface
{
    /** @var array<int, array{op:string, args:array}> */
    public static array $calls = [];
    /** @var array|null last merchantUpdate payload */
    public static ?array $lastMenu = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$lastMenu = null;
    }

    private static function record(string $op, array $args): array
    {
        self::$calls[] = ['op' => $op, 'args' => $args];
        return ['code' => 0, 'message' => 'ok', 'op' => $op];
    }

    public function merchantUpdate(array $payload): array
    {
        self::$lastMenu = $payload;
        return self::record('merchantUpdate', ['skus' => count($payload['skus'] ?? [])]);
    }

    public function confirmOrder(string $orderId): array { return self::record('confirm', ['orderId' => $orderId]); }
    public function cancelOrder(string $orderId, string $reason = ''): array { return self::record('cancel', ['orderId' => $orderId, 'reason' => $reason]); }
    public function prepareOrder(string $orderId): array { return self::record('prepare', ['orderId' => $orderId]); }
    public function collectOrder(string $orderId): array { return self::record('collect', ['orderId' => $orderId]); }
    public function agreeRefund(string $orderId): array { return self::record('agreeRefund', ['orderId' => $orderId]); }
    public function rejectRefund(string $orderId, string $reason = ''): array { return self::record('rejectRefund', ['orderId' => $orderId, 'reason' => $reason]); }

    public function ping(string $storeId): bool
    {
        return $storeId !== '';
    }
}
