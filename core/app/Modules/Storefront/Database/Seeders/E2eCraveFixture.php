<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use Illuminate\Support\Facades\DB;

/**
 * Resolves the E2E "crave-demo" store, its owner and its menu items by slug / name.
 *
 * The platform-v2 fixture seeders used to pin the ids of one legacy dev database
 * (store 3440, owner 10145, items 64944 / 64945), which do not exist in a freshly
 * seeded database (ids are small and ordering-dependent). Slug and item name are the
 * stable keys: E2ePlatformSeeder / ThemeShowcaseSeeder always create `crave-demo` with
 * these items, in every database.
 */
final class E2eCraveFixture
{
    public const SLUG = 'crave-demo';

    /** @return object{id:int,user_id:int}|null */
    public static function store(): ?object
    {
        $row = DB::table('stores')->where('slug', self::SLUG)->first(['id', 'user_id']);

        return $row ? (object) ['id' => (int) $row->id, 'user_id' => (int) $row->user_id] : null;
    }

    /**
     * The store's item whose name contains $name (names are stored per locale, so a LIKE on the
     * English name matches whichever column shape the database uses).
     */
    public static function itemId(int $storeId, string $name): ?int
    {
        $id = DB::table('items')->where('store_id', $storeId)->where('name', 'like', '%'.$name.'%')
            ->whereNull('deleted_at')->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }
}
