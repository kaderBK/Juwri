<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Retail;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Contracts\Wishlists;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\WishlistItem;

/**
 * P3-08 (aisle §12): a shopper's wishlist at one store. The owner of a list is either a signed-in customer of the
 * store (`customer_id`) or a guest, known only by the hash of a random token the browser keeps (`guest_key`; the
 * token itself is never stored). Lists hold items of that store only (the item is looked up inside the store, so an
 * id of another store is simply unknown). At sign-in the guest's list is merged into the customer's
 * ({@see merge()}): items already saved stay once, the guest rows are removed.
 */
final class Wishlist implements Wishlists
{
    /** A guest list owner: sha256 of the browser's token (40+ random characters). */
    public static function guestKey(string $token): ?string
    {
        $token = trim($token);

        return preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) === 1 ? hash('sha256', $token) : null;
    }

    /**
     * Item public ids on the list, newest first, that are still items of the store.
     *
     * @return list<array{item_id: string, variant_id: string|null, added_at: string|null}>
     */
    public function list(Store $store, ?int $customerId, ?string $guestKey): array
    {
        $rows = $this->owned($store, $customerId, $guestKey)
            ->join('items', 'items.id', '=', 'wishlist_items.menu_id')
            ->where('items.store_id', $store->id)
            ->whereNull('items.deleted_at')
            ->leftJoin('menu_variants', 'menu_variants.id', '=', 'wishlist_items.variant_id')
            ->orderByDesc('wishlist_items.id')
            ->limit(WishlistItem::MAX_ITEMS)
            ->get(['items.public_id as item_public_id', 'menu_variants.public_id as variant_public_id', 'wishlist_items.created_at']);

        return $rows->map(fn ($r) => [
            'item_id' => (string) $r->getAttribute('item_public_id'),
            'variant_id' => $r->getAttribute('variant_public_id') !== null ? (string) $r->getAttribute('variant_public_id') : null,
            'added_at' => $r->created_at !== null ? Carbon::parse($r->created_at)->toIso8601String() : null,
        ])->values()->all();
    }

    /**
     * Save an item (and optionally one variant) of the store; saving it again keeps one row (the variant is updated).
     *
     * @throws \DomainException not_found | no_owner
     */
    public function add(Store $store, ?int $customerId, ?string $guestKey, string $itemPublicId, ?string $variantPublicId = null): void
    {
        [$owner, $value] = $this->owner($customerId, $guestKey);
        $item = Menu::query()->where('store_id', $store->id)->where('public_id', $itemPublicId)->first();
        if ($item === null) {
            throw new \DomainException('not_found');
        }
        $variantId = null;
        if ($variantPublicId !== null && $variantPublicId !== '') {
            $variantId = MenuVariant::query()->where('menu_id', $item->id)->where('public_id', $variantPublicId)->value('id');
            if ($variantId === null) {
                throw new \DomainException('not_found');
            }
        }

        $existing = WishlistItem::query()->where('store_id', $store->id)->where($owner, $value)->where('menu_id', $item->id)->first();
        if ($existing !== null) {
            if ($variantId !== null && (int) $existing->variant_id !== (int) $variantId) {
                $existing->forceFill(['variant_id' => $variantId])->save();
            }

            return;
        }
        try {
            DB::transaction(fn () => WishlistItem::create([
                'store_id' => $store->id, $owner => $value, 'menu_id' => $item->id, 'variant_id' => $variantId, 'created_at' => now(),
            ]));
        } catch (QueryException) {
            return; // a concurrent save of the same item won the unique index
        }
        $this->trim($store, $owner, $value);
    }

    /** Remove an item from the list (no error when it was not on it). */
    public function remove(Store $store, ?int $customerId, ?string $guestKey, string $itemPublicId): void
    {
        [$owner, $value] = $this->owner($customerId, $guestKey);
        $itemId = Menu::query()->where('store_id', $store->id)->where('public_id', $itemPublicId)->value('id');
        if ($itemId !== null) {
            WishlistItem::query()->where('store_id', $store->id)->where($owner, $value)->where('menu_id', $itemId)->delete();
        }
    }

    /**
     * Sign-in sync: move the guest's list into the customer's (dedupe by item) and, for a headless client that kept
     * its list itself, add those item ids too. Unknown ids are skipped. Returns the merged list.
     *
     * @param  list<string>  $itemPublicIds
     * @return list<array{item_id: string, variant_id: string|null, added_at: string|null}>
     */
    public function merge(Store $store, int $customerId, ?string $guestKey, array $itemPublicIds = []): array
    {
        DB::transaction(function () use ($store, $customerId, $guestKey, $itemPublicIds) {
            if ($guestKey !== null) {
                $guestRows = WishlistItem::query()->where('store_id', $store->id)->where('guest_key', $guestKey)->orderBy('id')->get();
                $have = WishlistItem::query()->where('store_id', $store->id)->where('customer_id', $customerId)->pluck('menu_id')->map(fn ($id) => (int) $id)->all();
                foreach ($guestRows as $row) {
                    if (in_array((int) $row->menu_id, $have, true)) {
                        $row->delete();

                        continue;
                    }
                    $row->forceFill(['customer_id' => $customerId, 'guest_key' => null])->save();
                    $have[] = (int) $row->menu_id;
                }
            }
            foreach (array_slice(array_values(array_unique(array_map('strval', $itemPublicIds))), 0, WishlistItem::MAX_ITEMS) as $publicId) {
                try {
                    $this->add($store, $customerId, null, $publicId);
                } catch (\DomainException) {
                    // an id this store does not have: skipped
                }
            }
        });
        $this->trim($store, 'customer_id', $customerId);

        return $this->list($store, $customerId, null);
    }

    /** @return Builder<WishlistItem> */
    private function owned(Store $store, ?int $customerId, ?string $guestKey)
    {
        [$owner, $value] = $this->owner($customerId, $guestKey);

        return WishlistItem::query()->where('wishlist_items.store_id', $store->id)->where('wishlist_items.'.$owner, $value);
    }

    /** @return array{0: string, 1: int|string} */
    private function owner(?int $customerId, ?string $guestKey): array
    {
        if ($customerId !== null && $customerId > 0) {
            return ['customer_id', $customerId];
        }
        if ($guestKey !== null && $guestKey !== '') {
            return ['guest_key', $guestKey];
        }
        throw new \DomainException('no_owner');
    }

    /** Keep the newest MAX_ITEMS of one list. */
    private function trim(Store $store, string $owner, int|string $value): void
    {
        $keep = WishlistItem::query()->where('store_id', $store->id)->where($owner, $value)->orderByDesc('id')->limit(WishlistItem::MAX_ITEMS)->pluck('id')->all();
        if (count($keep) >= WishlistItem::MAX_ITEMS) {
            WishlistItem::query()->where('store_id', $store->id)->where($owner, $value)->whereNotIn('id', $keep)->delete();
        }
    }
}
