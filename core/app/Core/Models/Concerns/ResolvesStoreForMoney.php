<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models\Concerns;

use Illuminate\Support\Facades\DB;
use Juwri\Core\Support\RequestEpoch;

/**
 * Lets a model that does not carry `store_id` tell HasMinorMoney (row currency,
 * store-precision format) which store its money belongs to — a check payment via its check, a PO line via its PO.
 *
 * The lookup is a single `value()` on the parent's owner column, cached per
 * (table, foreign key) for the life of the request. It deliberately does NOT touch
 * the Eloquent relation: `$this->purchaseOrder->store_id` would lazy-load a full
 * model per row, so a 200-line report would fire 200 queries inside a cast.
 */
trait ResolvesStoreForMoney
{
    /** @var array<string, int|null> "table:fk" => store id */
    protected static array $storeIdCache = [];

    /** The application instance $storeIdCache was filled for. */
    protected static ?int $cacheApp = null;

    /**
     * Bind the cache to the current application instance, as StoreOption does for
     * its option memo.
     *
     * The key is "table:row id", which says nothing about WHICH database the row
     * came from. Under the test runner that is a cross-class leak: RefreshDatabase
     * rolls the parent row back but hands the next test the same low ids, so a
     * line item would resolve to a store from a database that no longer exists —
     * and its money would then take that store's currency.
     */
    protected static function scopeStoreIdCache(): void
    {
        $id = RequestEpoch::id();
        if (static::$cacheApp !== $id) {
            static::$cacheApp = $id;
            static::$storeIdCache = [];
        }
    }

    /**
     * @param  string  $table  parent table, unprefixed
     * @param  string  $foreignKey  the column on THIS row holding the parent's id
     * @param  string  $ownerColumn  the column on the parent holding the store id
     */
    protected function storeIdVia(string $table, string $foreignKey, string $ownerColumn = 'store_id'): ?int
    {
        $id = $this->getAttribute($foreignKey);
        if (! $id) {
            return null;
        }

        static::scopeStoreIdCache();

        $key = "{$table}:{$id}";

        return static::$storeIdCache[$key] ??= DB::table($table)
            ->where('id', $id)
            ->value($ownerColumn);
    }

    /** Testing seam. */
    public static function flushStoreIdCache(): void
    {
        static::$storeIdCache = [];
    }
}
