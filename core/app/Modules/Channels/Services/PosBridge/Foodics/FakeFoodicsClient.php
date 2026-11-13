<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Foodics;

use RuntimeException;

/**
 * Fake Foodics client: serves fixture branches/products/modifiers, records order calls,
 * and enforces the driver's contracts — calculateOrder must run before createOrder, and
 * createOrder must carry a UUIDv4 id (a duplicate id throws a 422-equivalent so the
 * idempotency path is exercised). Never hits the network.
 */
class FakeFoodicsClient implements FoodicsClientInterface
{
    /** @var array<int, array> every createOrder body received */
    public static array $created = [];
    /** @var array<int, array> every calculateOrder body received */
    public static array $calculated = [];
    /** @var array<string, bool> seen UUIDs (dup → 422) */
    public static array $seenUuids = [];

    public static function reset(): void
    {
        self::$created = [];
        self::$calculated = [];
        self::$seenUuids = [];
    }

    public function whoami(): array
    {
        return ['data' => ['id' => 'wh_1', 'name' => 'Fake Foodics Business', 'currency' => 'SAR']];
    }

    public function listBranches(): array
    {
        return ['data' => [
            ['id' => 'br_1', 'name' => 'Main', 'reference' => 'B1', 'receives_online_orders' => true],
            ['id' => 'br_2', 'name' => 'Downtown', 'reference' => 'B2', 'receives_online_orders' => false],
        ]];
    }

    public function listProducts(int $page = 1): array
    {
        if ($page > 1) {
            return ['data' => [], 'meta' => ['current_page' => $page, 'last_page' => 1]];
        }
        return ['data' => [
            [
                'id' => 'p_burger', 'name' => 'Burger', 'name_localized' => 'برجر', 'price' => 25.0, 'sku' => 'SKU-B',
                'category' => ['id' => 'c_food', 'name' => 'Food', 'name_localized' => 'طعام'],
                'modifiers' => [
                    ['id' => 'mg_size', 'name' => 'Size', 'is_required' => true, 'min_options' => 1, 'max_options' => 1,
                     'options' => [
                        ['id' => 'mo_reg', 'name' => 'Regular', 'price' => 0.0],
                        ['id' => 'mo_large', 'name' => 'Large', 'price' => 5.0],
                     ]],
                ],
            ],
            [
                'id' => 'p_water', 'name' => 'Water', 'name_localized' => 'ماء', 'price' => 3.0, 'sku' => 'SKU-W',
                'category' => ['id' => 'c_drink', 'name' => 'Drinks', 'name_localized' => 'مشروبات'],
                'modifiers' => [],
            ],
        ], 'meta' => ['current_page' => 1, 'last_page' => 1]];
    }

    public function listModifiers(): array
    {
        return ['data' => [
            ['id' => 'mg_size', 'name' => 'Size', 'options' => [
                ['id' => 'mo_reg', 'name' => 'Regular', 'price' => 0.0],
                ['id' => 'mo_large', 'name' => 'Large', 'price' => 5.0],
            ]],
        ]];
    }

    public function calculateOrder(array $body): array
    {
        self::$calculated[] = $body;
        // Their engine "recomputes" — echo a deterministic authoritative total.
        $subtotal = 0.0;
        foreach ($body['products'] ?? [] as $p) {
            $subtotal += (float) ($p['unit_price'] ?? 0) * (int) ($p['quantity'] ?? 1);
        }
        $tax = round($subtotal * 0.15, 2); // KSA VAT 15%
        return ['data' => ['subtotal' => $subtotal, 'tax' => $tax, 'total' => round($subtotal + $tax, 2)]];
    }

    public function createOrder(array $body): array
    {
        $uuid = (string) ($body['id'] ?? '');
        if ($uuid === '') {
            throw new RuntimeException('Foodics createOrder requires a client UUID id');
        }
        if (isset(self::$seenUuids[$uuid])) {
            // Foodics returns 422 on a duplicate id — the driver treats it as success.
            throw new FoodicsDuplicateException('Order id already exists (422)');
        }
        self::$seenUuids[$uuid] = true;
        self::$created[] = $body;
        return ['data' => ['id' => 'fo_' . substr(md5($uuid), 0, 10), 'reference' => $body['meta']['reference'] ?? null]];
    }
}
