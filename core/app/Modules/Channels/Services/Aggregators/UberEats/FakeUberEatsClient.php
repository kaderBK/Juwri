<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\UberEats;




/**
 * Default Uber Eats client for tests / no-creds. Serves a scripted order and records
 * accept/deny calls in memory — never touches the network.
 */
class FakeUberEatsClient implements UberEatsClientInterface
{
    /** @var array<string, array> scripted orders keyed by id */
    public static array $orders = [];
    /** @var array<int, array{action:string,orderId:string,reason?:string}> */
    public static array $calls = [];

    public function fetchOrder(string $orderId): array
    {
        return self::$orders[$orderId] ?? [
            'id' => $orderId,
            'store' => ['id' => 'UE-STORE-1'],
            'customer' => ['name' => 'Test Eater', 'phone' => '+100'],
            'cart' => ['items' => []],
            'payment' => ['charges' => ['total' => ['amount' => 0]]],
        ];
    }

    public function acceptOrder(string $orderId): void
    {
        self::$calls[] = ['action' => 'accept', 'orderId' => $orderId];
    }

    public function denyOrder(string $orderId, string $reason = 'STORE_CLOSED'): void
    {
        self::$calls[] = ['action' => 'deny', 'orderId' => $orderId, 'reason' => $reason];
    }

    public static function reset(): void
    {
        self::$orders = [];
        self::$calls = [];
    }
}
