<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Database\Factories;

use Juwri\Modules\LegacyCompat\Support\LegacyRoomTokenKeys;

/**
 * M7-A: the synthetic legacy source set — one busy old install, shaped like the old platform's rows: 30 stores over
 * 10 owners, mixed currencies, branches, tables and hotel rooms, menu, customers, orders, loyalty and gift cards,
 * plus two stores whose owner does not exist (the importer refuses those).
 *
 * Every value is invented (J19) and deterministic: ids are built from the store id, keys from hash('sha256') of the
 * row id, the printed room tokens from the room-token signer. The same call on two fresh sources writes identical
 * rows and returns an identical manifest, so a test can assert against that manifest.
 */
final class LegacyMbcFixture
{
    /** The old install's app key (invented). The printed room tokens below are minted under it. */
    public const OLD_APP_KEY = 'base64:c3ludGhldGljLXJlcGxheS1vbGQta2V5LTAwMDAwMDAwMDA=';

    /** The first / last store id of the set. */
    public const FIRST_STORE_ID = 101;

    public const LAST_STORE_ID = 130;

    /** The owner id that does NOT exist in the source: the stores it owns are refused by the importer. */
    public const MISSING_OWNER_ID = 99;

    /** The store ids owned by {@see self::MISSING_OWNER_ID}. */
    public const ORPHAN_STORE_IDS = [125, 126];

    /** The last store id that carries hotel rooms (101..110). */
    public const HOTEL_STORE_LAST_ID = 110;

    /** The last store id that carries a gift card (101..105). */
    public const GIFT_CARD_STORE_LAST_ID = 105;

    /** The owner user ids of the set (1..10): three stores each. */
    public const FIRST_OWNER_ID = 1;

    public const LAST_OWNER_ID = 10;

    /** How many stores one owner has. */
    public const STORES_PER_OWNER = 3;

    /** The slugs of the first ten stores, in store-id order (mixed case, numeric, unicode, reserved words). */
    private const NAMED_SLUGS = [
        'cafe-one', 'Mixed-Case-Cafe', '12345', 'مطعم-x', 'admin',
        'r', 'ar', 'store with-dash_1', 'pizza-place', 'sushi-bar',
    ];

    /** The item prices of every store, in cycle order (float noise and a 3-decimal currency included). */
    private const ITEM_PRICES = [9.99, 0.1 + 0.2, 1.2345, 5, 12.5, 0.005];

    /**
     * Fill an EMPTY source (schema only, see {@see LegacySourceFixture::createSchema()}) with the synthetic legacy
     * source set and return the manifest of what was written.
     *
     * @return array{
     *     stores: list<array{
     *         id: int,
     *         owner_id: int,
     *         slug: string,
     *         active: bool,
     *         tables: list<array{id: int, key: string, area_type: string, branch_id: ?int}>,
     *         rooms: list<array{id: int, key: string, token: string}>,
     *         branches: list<int>,
     *         items: list<int>
     *     }>,
     *     old_app_key: string
     * }
     */
    public static function populate(LegacySourceFixture $src): array
    {
        $src->platformOption('currency_code', 'USD');

        for ($ownerId = self::FIRST_OWNER_ID; $ownerId <= self::LAST_OWNER_ID; $ownerId++) {
            $src->user($ownerId, ['name' => 'Owner '.$ownerId]);
        }

        $stores = [];
        for ($storeId = self::FIRST_STORE_ID; $storeId <= self::LAST_STORE_ID; $storeId++) {
            $stores[] = self::writeStore($src, $storeId);
        }

        return ['stores' => $stores, 'old_app_key' => self::OLD_APP_KEY];
    }

    /**
     * Write one store and everything under it; returns the store's manifest entry.
     *
     * @return array{
     *     id: int,
     *     owner_id: int,
     *     slug: string,
     *     active: bool,
     *     tables: list<array{id: int, key: string, area_type: string, branch_id: ?int}>,
     *     rooms: list<array{id: int, key: string, token: string}>,
     *     branches: list<int>,
     *     items: list<int>
     * }
     */
    private static function writeStore(LegacySourceFixture $src, int $storeId): array
    {
        $orphan = in_array($storeId, self::ORPHAN_STORE_IDS, true);
        $ownerId = $orphan ? self::MISSING_OWNER_ID : self::ownerId($storeId);
        $slug = self::slug($storeId);

        $src->store($storeId, $ownerId, ['slug' => $slug, 'main_image' => 'default.png', 'cover_image' => 'default.png']);
        $src->storeOption($storeId, 'currency_code', self::currency($storeId));

        $manifest = [
            'id' => $storeId,
            'owner_id' => $ownerId,
            'slug' => $slug,
            'active' => ! $orphan,
            'tables' => [],
            'rooms' => [],
            'branches' => [],
            'items' => [],
        ];

        if ($orphan) {
            // The owner does not exist, so the importer refuses the store. It gets one table row only: a row the
            // importer never reaches, because the store itself is skipped before its rows are read.
            $tableId = $storeId * 100 + 1;
            $key = self::tableKey($tableId, $storeId);
            $src->table($tableId, $storeId, $key, ['area_type' => 'table']);
            $manifest['tables'][] = ['id' => $tableId, 'key' => $key, 'area_type' => 'table', 'branch_id' => null];

            return $manifest;
        }

        // Two branches per store.
        $branchIds = [];
        for ($n = 1; $n <= 2; $n++) {
            $branchId = 1000 + $storeId * 10 + $n;
            $src->branch($branchId, $storeId);
            $branchIds[] = $branchId;
        }
        $manifest['branches'] = $branchIds;

        // Five to twenty tables per store, alternating between the two branches.
        $tableCount = 5 + ($storeId % 16);
        for ($n = 1; $n <= $tableCount; $n++) {
            $tableId = $storeId * 100 + $n;
            $key = self::tableKey($tableId, $storeId);
            $branchId = $branchIds[$n % 2];
            $src->table($tableId, $storeId, $key, ['area_type' => 'table', 'branch_id' => $branchId]);
            $manifest['tables'][] = ['id' => $tableId, 'key' => $key, 'area_type' => 'table', 'branch_id' => $branchId];
        }

        // Three hotel rooms, on the first ten stores only. A room is a table row with area_type 'room'; the token
        // printed on the room QR is minted under the old app key.
        if ($storeId <= self::HOTEL_STORE_LAST_ID) {
            for ($n = 1; $n <= 3; $n++) {
                $roomId = $storeId * 100 + 90 + $n;
                $key = self::roomKey($roomId);
                $token = LegacyRoomTokenKeys::sign($storeId, $roomId, self::OLD_APP_KEY);
                $src->table($roomId, $storeId, $key, ['area_type' => 'room', 'branch_id' => $branchIds[0]]);
                $manifest['rooms'][] = ['id' => $roomId, 'key' => $key, 'token' => $token];
            }
        }

        return self::writeCatalogue($src, $storeId, $manifest, $branchIds);
    }

    /**
     * Write the menu, the customers, the orders, the loyalty rows and the gift card of one store.
     *
     * @param  array<string, mixed>  $manifest  the store's manifest entry; its 'items' list is filled here
     * @param  list<int>  $branchIds
     * @return array<string, mixed>
     */
    private static function writeCatalogue(LegacySourceFixture $src, int $storeId, array $manifest, array $branchIds): array
    {
        // Three menu categories (the third one a child of the first) and six items with cycling prices.
        $categoryIds = [];
        for ($n = 1; $n <= 3; $n++) {
            $categoryId = $storeId * 100 + 40 + $n;
            $src->category($categoryId, $storeId, ['parent' => $n === 3 ? $categoryIds[0] : null]);
            $categoryIds[] = $categoryId;
        }

        $itemIds = [];
        foreach (self::ITEM_PRICES as $index => $price) {
            $itemId = $storeId * 100 + 60 + $index + 1;
            $src->item($itemId, $storeId, $categoryIds[$index % 3], $price);
            $itemIds[] = $itemId;
        }
        $manifest['items'] = $itemIds;

        // Four customers.
        $customerIds = [];
        for ($n = 1; $n <= 4; $n++) {
            $customerId = $storeId * 100 + 70 + $n;
            $src->customer($customerId, $storeId);
            $customerIds[] = $customerId;
        }

        // Five orders, each with one to three lines on the store's own items.
        for ($n = 1; $n <= 5; $n++) {
            $orderId = $storeId * 1000 + $n;
            $src->order($orderId, $storeId, [
                'customer_id' => $customerIds[$n % 4],
                'branch_id' => $branchIds[$n % 2],
            ]);
            $lineCount = 1 + ($n % 3);
            for ($line = 1; $line <= $lineCount; $line++) {
                $src->orderItem($storeId * 10000 + $n * 10 + $line, $orderId, $itemIds[($n + $line) % 6], $line);
            }
        }

        // One loyalty programme, two accounts with one ledger row each (balance_after = the account balance).
        $src->loyaltyProgram($storeId * 1000 + 100, $storeId);
        for ($n = 1; $n <= 2; $n++) {
            $accountId = $storeId * 1000 + 200 + $n;
            $balance = 5 * $n;
            $src->loyaltyAccount($accountId, $storeId, $customerIds[$n - 1], ['balance' => $balance]);
            $src->ledger($storeId * 1000 + 300 + $n, $accountId, $balance, $balance);
        }

        // One gift card, on the first five stores only.
        if ($storeId <= self::GIFT_CARD_STORE_LAST_ID) {
            $src->giftCard(
                $storeId * 1000 + 400,
                $storeId,
                self::giftCardCode($storeId),
                'USD',
                25.0,
                ['purchaser_customer_id' => $customerIds[0]],
            );
        }

        return $manifest;
    }

    /** The owner of a store: three stores per owner, in store-id order. */
    private static function ownerId(int $storeId): int
    {
        return self::FIRST_OWNER_ID + intdiv($storeId - self::FIRST_STORE_ID, self::STORES_PER_OWNER);
    }

    /** The slug of a store: the ten named ones, then shop-<n>. */
    private static function slug(int $storeId): string
    {
        $index = $storeId - self::FIRST_STORE_ID;

        return $index < count(self::NAMED_SLUGS)
            ? self::NAMED_SLUGS[$index]
            : 'shop-'.($storeId - 100);
    }

    /** The currency of a store: SAR, USD and KWD in a three-cycle (the platform option is USD). */
    private static function currency(int $storeId): string
    {
        return match ($storeId % 3) {
            0 => 'SAR',
            1 => 'USD',
            default => 'KWD',
        };
    }

    /** A table key: globally unique, derived from the row id and the store id. */
    private static function tableKey(int $tableId, int $storeId): string
    {
        return sprintf('K-%s-%d', substr(hash('sha256', 't'.$tableId), 0, 10), $storeId);
    }

    /** A room key: globally unique, derived from the room id. */
    private static function roomKey(int $roomId): string
    {
        return 'R-'.substr(hash('sha256', 'r'.$roomId), 0, 10);
    }

    /** A gift card code: globally unique, derived from the store id. */
    private static function giftCardCode(int $storeId): string
    {
        return 'GC-'.strtoupper(substr(hash('sha256', 'g'.$storeId), 0, 12));
    }
}
