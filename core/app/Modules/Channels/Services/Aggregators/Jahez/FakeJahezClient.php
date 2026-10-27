<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Jahez;




/**
 * Fake Jahez client: records lifecycle calls + last menu upload. Fixtures follow the
 * reconstructed shape (JahezPayloadMapper); never hits the network.
 */
class FakeJahezClient implements JahezClientInterface
{
    /** @var array<int, array{op:string, args:array}> */
    public static array $calls = [];
    public static ?array $lastMenu = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$lastMenu = null;
    }

    private static function record(string $op, array $args): array
    {
        self::$calls[] = ['op' => $op, 'args' => $args];
        return ['status' => 'success', 'op' => $op];
    }

    public function authenticate(string $apiKey, string $secret): array
    {
        return ['token' => 'fake-jahez-token', 'expires_in' => 3600];
    }

    public function uploadMenu(array $payload): array
    {
        self::$lastMenu = $payload;
        return self::record('uploadMenu', ['categories' => count($payload['categories'] ?? [])]);
    }

    public function acceptOrder(string $orderId): array { return self::record('accept', ['orderId' => $orderId]); }
    public function rejectOrder(string $orderId, string $reason): array { return self::record('reject', ['orderId' => $orderId, 'reason' => $reason]); }
    public function updateStatus(string $orderId, string $status): array { return self::record('status', ['orderId' => $orderId, 'status' => $status]); }

    public function ping(string $branchId): bool
    {
        return $branchId !== '';
    }
}
