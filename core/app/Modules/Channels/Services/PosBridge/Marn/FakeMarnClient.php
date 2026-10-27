<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Marn;




/**
 * Fake Marn client: fixture locations/products, records PAID/HOLD pushes, returns a
 * deterministic OrderNo. Never hits the network.
 */
class FakeMarnClient implements MarnClientInterface
{
    /** @var array<int, array> PAID push bodies */
    public static array $paid = [];
    /** @var array<int, array> HOLD push bodies */
    public static array $hold = [];
    /** @var array<string, bool> idempotency keys already seen (mirrors Marn's natural dedupe) */
    public static array $seenKeys = [];

    public static function reset(): void
    {
        self::$paid = [];
        self::$hold = [];
        self::$seenKeys = [];
    }

    public function fetchLocations(): array
    {
        return ['data' => [
            ['location_key' => 'LK1', 'name' => 'Main', 'currency' => 'SAR', 'order_types' => ['dine_in', 'pickup', 'delivery']],
        ]];
    }

    public function fetchLocationProducts(string $locationKey): array
    {
        return ['data' => [
            [
                'product_key' => 'MP_SHAWARMA', 'name' => 'Shawarma', 'name_ar' => 'شاورما', 'price' => 18.0,
                'category' => ['key' => 'MC_WRAPS', 'name' => 'Wraps', 'name_ar' => 'راب'],
                'modifier_groups' => [
                    ['key' => 'MG_SAUCE', 'name' => 'Sauce', 'required' => false, 'min' => 0, 'max' => 2,
                     'modifiers' => [
                        ['key' => 'MO_GARLIC', 'name' => 'Garlic', 'price' => 0.0],
                        ['key' => 'MO_HOT', 'name' => 'Hot', 'price' => 1.0],
                     ]],
                ],
            ],
            [
                'product_key' => 'MP_JUICE', 'name' => 'Juice', 'name_ar' => 'عصير', 'price' => 8.0,
                'category' => ['key' => 'MC_DRINKS', 'name' => 'Drinks', 'name_ar' => 'مشروبات'],
                'modifier_groups' => [],
            ],
        ]];
    }

    public function createPaidOrder(array $body): array
    {
        $this->guardDuplicate($body);
        self::$paid[] = $body;
        return ['order_no' => 'MARN-P-' . substr(md5(json_encode($body['products'] ?? []) . ($body['reference'] ?? '')), 0, 8)];
    }

    public function createHoldOrder(array $body): array
    {
        $this->guardDuplicate($body);
        self::$hold[] = $body;
        return ['order_no' => 'MARN-H-' . substr(md5(json_encode($body['products'] ?? []) . ($body['reference'] ?? '')), 0, 8)];
    }

    /** Reject a re-used idempotency key, exactly as the real Marn API would on a retry. */
    private function guardDuplicate(array $body): void
    {
        $key = (string) ($body['idempotency_key'] ?? '');
        if ($key !== '' && isset(self::$seenKeys[$key])) {
            throw new MarnDuplicateException('duplicate idempotency_key: ' . $key);
        }
        if ($key !== '') {
            self::$seenKeys[$key] = true;
        }
    }

    public function ping(string $locationKey): bool
    {
        return $locationKey !== '';
    }
}
