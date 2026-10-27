<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Zomato;




/**
 * PARITY-RUN T7 — the Zomato mock server. Records every lifecycle call and the
 * last published menu; never touches the network, so CI proves the whole driver
 * without partner credentials.
 *
 * `$failNext` lets a test drive the failure branch (a 4xx/5xx from the partner)
 * without stubbing HTTP: the next call throws, exactly like a real transport
 * error, and the caller's sync-log/error path is exercised for real.
 */
class FakeZomatoClient implements ZomatoClientInterface
{
    /** @var array<int, array{op:string, args:array}> */
    public static array $calls = [];

    public static ?array $lastMenu = null;

    /** When set, the next call throws this message and the flag clears. */
    public static ?string $failNext = null;

    /** Outlet id => online flag, as the fake partner sees it. */
    public static array $outletOnline = [];

    /** Item code => available flag, as the fake partner sees it. */
    public static array $itemAvailability = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$lastMenu = null;
        self::$failNext = null;
        self::$outletOnline = [];
        self::$itemAvailability = [];
    }

    /** @return array<int, string> */
    public static function ops(): array
    {
        return array_column(self::$calls, 'op');
    }

    public static function lastCall(string $op): ?array
    {
        foreach (array_reverse(self::$calls) as $call) {
            if ($call['op'] === $op) {
                return $call['args'];
            }
        }

        return null;
    }

    private static function record(string $op, array $args): array
    {
        if (self::$failNext !== null) {
            $message = self::$failNext;
            self::$failNext = null;
            throw new \RuntimeException($message);
        }

        self::$calls[] = ['op' => $op, 'args' => $args];

        return ['status' => 'success', 'op' => $op];
    }

    public function authenticate(string $apiKey, string $apiSecret): array
    {
        return ['token' => 'fake-zomato-token', 'expires_in' => 3600];
    }

    public function publishMenu(array $payload): array
    {
        $result = self::record('publishMenu', ['items' => count($payload['items'] ?? [])]);
        self::$lastMenu = $payload;

        return $result;
    }

    public function acknowledgeOrder(string $orderId, int $prepMinutes = 20): array
    {
        return self::record('acknowledgeOrder', ['orderId' => $orderId, 'prepMinutes' => $prepMinutes]);
    }

    public function rejectOrder(string $orderId, string $reason): array
    {
        return self::record('rejectOrder', ['orderId' => $orderId, 'reason' => $reason]);
    }

    public function updateOrderStatus(string $orderId, string $status): array
    {
        return self::record('updateOrderStatus', ['orderId' => $orderId, 'status' => $status]);
    }

    public function setItemAvailability(string $itemCode, bool $available): array
    {
        $result = self::record('setItemAvailability', ['itemCode' => $itemCode, 'available' => $available]);
        self::$itemAvailability[$itemCode] = $available;

        return $result;
    }

    public function setOutletOnline(string $storeId, bool $online): array
    {
        $result = self::record('setOutletOnline', ['storeId' => $storeId, 'online' => $online]);
        self::$outletOnline[$storeId] = $online;

        return $result;
    }

    public function ping(string $storeId): bool
    {
        return $storeId !== '';
    }
}
