<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services;

use Juwri\Core\Support\RequestEpoch;
use Juwri\Modules\Menu\Contracts\QuoteItems;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P4-16 follow-up (load test: 64 queries for a 3-line cart): while a cart is being priced, every lookup of one of the
 * store's items returns the same instance, loaded once with the relations the pricing reads (attached and legacy
 * choice groups with their options, attached and legacy add-ons, variants). Menu::resolved_variant_options and
 * resolved_extras then answer from those loaded relations (they already prefer loaded relations), so a cart line no
 * longer re-queries its groups for each pass.
 *
 * Only inside {@see during()} (the storefront quote); everywhere else, and once it returns, callers get a fresh
 * query exactly as before. P6-148: order placement opens its own scope (StorefrontOrderingService::orderLines,
 * OrderApiService::resolveLines) — a fresh read of the order's items, batched, not the quote's. The scope is always closed in `finally`
 * (Octane workers never carry it to the next request). Lookups are always store-scoped.
 */
final class QuoteItemCache implements QuoteItems
{
    /** Relations the quote reads (the same constraints as the lazy queries they replace). */
    public const WITH = ['attachedVariantOptions', 'variantOptions', 'attachedExtras', 'extras', 'variants', 'branchSettings'];

    private static int $depth = 0;

    /** @var array<string, Menu|null> "store:public:<uuid>" / "store:id:<id>" => item (null = not this store's) */
    private static array $items = [];

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function during(callable $fn): mixed
    {
        if (self::$depth === 0 || self::$epoch !== (string) RequestEpoch::id()) {
            self::$depth = 0;
            self::$items = [];
            self::$epoch = (string) RequestEpoch::id();
        }
        self::$depth++;
        try {
            return $fn();
        } finally {
            if (--self::$depth <= 0) {
                self::$depth = 0;
                self::$items = [];
            }
        }
    }

    /** The request that opened the scope (Octane: a scope never leaks into another request). */
    private static ?string $epoch = null;

    public function active(): bool
    {
        if (self::$depth > 0 && self::$epoch !== (string) RequestEpoch::id()) {
            self::$depth = 0;
            self::$items = [];
        }

        return self::$depth > 0;
    }

    /**
     * Load the cart's items in one batch (each relation one query for all of them). Ids that are not this store's
     * are remembered as missing, exactly as a single lookup would answer.
     *
     * @param  list<string>  $publicIds
     */
    public function prime(int $storeId, array $publicIds): void
    {
        if (! $this->active()) {
            return;
        }
        $wanted = array_values(array_unique(array_filter($publicIds, fn ($id) => $id !== '' && ! array_key_exists($storeId.':public:'.$id, self::$items))));
        if ($wanted === []) {
            return;
        }
        $found = Menu::query()->where('store_id', $storeId)->whereIn('public_id', $wanted)->with(self::WITH)->get()->keyBy(fn (Menu $m) => (string) $m->public_id);
        foreach ($wanted as $id) {
            self::remember($storeId.':public:'.$id, $found->get($id), $storeId);
        }
    }

    /**
     * {@see prime()} by numeric id (the legacy theme carts and the goods cart name items by id).
     *
     * @param  list<mixed>  $ids
     */
    public function primeIds(int $storeId, array $ids): void
    {
        if (! $this->active()) {
            return;
        }
        $wanted = [];
        foreach ($ids as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && ! array_key_exists($storeId.':id:'.(int) $id, self::$items)) {
                $wanted[(int) $id] = (int) $id;
            }
        }
        if ($wanted === []) {
            return;
        }
        $found = Menu::query()->where('store_id', $storeId)->whereIn('id', array_values($wanted))->with(self::WITH)->get()->keyBy(fn (Menu $m) => (int) $m->id);
        foreach ($wanted as $id) {
            self::remember($storeId.':id:'.$id, $found->get($id), $storeId);
        }
    }

    public function byPublicId(int $storeId, string $publicId): ?Menu
    {
        $query = fn () => Menu::query()->where('store_id', $storeId)->where('public_id', $publicId);
        if (! $this->active()) {
            return $query()->first();
        }
        $key = $storeId.':public:'.$publicId;
        if (! array_key_exists($key, self::$items)) {
            self::remember($key, $query()->with(self::WITH)->first(), $storeId);
        }

        return self::$items[$key];
    }

    /** @param  mixed  $id  passed to find() unchanged (as the callers always did) */
    public function byId(int $storeId, mixed $id): ?Menu
    {
        if (! $this->active() || ! (is_int($id) || (is_string($id) && ctype_digit($id)))) {
            $found = Menu::where('store_id', $storeId)->find($id);

            return $found instanceof Menu ? $found : null;
        }
        $key = $storeId.':id:'.(int) $id;
        if (! array_key_exists($key, self::$items)) {
            self::remember($key, Menu::where('store_id', $storeId)->with(self::WITH)->find((int) $id), $storeId);
        }

        return self::$items[$key];
    }

    private static function remember(string $key, ?Menu $item, int $storeId): void
    {
        self::$items[$key] = $item;
        if ($item !== null) {
            self::$items[$storeId.':id:'.$item->id] = $item;
            if ((string) $item->public_id !== '') {
                self::$items[$storeId.':public:'.$item->public_id] = $item;
            }
        }
    }
}
