<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Clover;

use RuntimeException;

/**
 * Fake Clover client: records atomic-order calls and asserts each carries a non-empty
 * idempotency key + a resolved orderType id. Never hits the network.
 */
class FakeCloverClient implements CloverClientInterface
{
    /** @var array<int, array{payload:array,key:string}> */
    public static array $calls = [];
    public static array $orderTypes = [
        ['id' => 'OT_ONLINE', 'label' => 'Online Order'],
        ['id' => 'OT_DINEIN', 'label' => 'Dine In'],
    ];
    public static array $inventory = [
        ['external_id' => 'CLV_1', 'name' => 'Espresso'],
        ['external_id' => 'CLV_2', 'name' => 'Croissant'],
    ];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function createAtomicOrder(array $payload, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') {
            throw new RuntimeException('Clover atomic order requires an idempotency key');
        }
        if (empty($payload['orderCart']['orderType']['id'])) {
            throw new RuntimeException('Clover atomic order requires an orderType id');
        }
        self::$calls[] = ['payload' => $payload, 'key' => $idempotencyKey];

        return ['id' => 'clv_ord_' . substr(md5($idempotencyKey), 0, 12)];
    }

    public function listOrderTypes(): array
    {
        return self::$orderTypes;
    }

    public function listInventory(): array
    {
        return self::$inventory;
    }

    public function ping(string $merchantId): bool
    {
        return $merchantId !== '';
    }
}
