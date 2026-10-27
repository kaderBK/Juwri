<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Square;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fake Square client: records CreateOrder calls and asserts each carries a non-empty
 * idempotency_key (Square rejects duplicates by key — the driver's replay-safety
 * contract). Never hits the network.
 */
class FakeSquareClient implements SquareClientInterface
{
    /** @var array<int, array> every createOrder body received */
    public static array $calls = [];
    /** @var array<int, array> catalog returned to the mapping screen */
    public static array $catalog = [
        ['external_id' => 'SQ_ITEM_1', 'name' => 'Latte'],
        ['external_id' => 'SQ_ITEM_2', 'name' => 'Muffin'],
    ];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function createOrder(array $body): array
    {
        if (empty($body['idempotency_key'])) {
            throw new RuntimeException('Square createOrder requires an idempotency_key');
        }
        self::$calls[] = $body;

        return ['id' => 'sq_ord_' . substr(md5($body['idempotency_key']), 0, 12)];
    }

    public function listCatalog(): array
    {
        return self::$catalog;
    }

    public function ping(string $locationId): bool
    {
        return $locationId !== '';
    }
}
