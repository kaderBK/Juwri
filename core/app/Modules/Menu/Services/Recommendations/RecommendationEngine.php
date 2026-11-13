<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Recommendations;

use Illuminate\Database\Eloquent\Builder;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Contracts\Recommendations;
use Juwri\Modules\Menu\Data\Recommendation;
use Juwri\Modules\Menu\Data\RecommendationCustomer;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P1-17 (J27-B): the one upsell / recommendation rules engine.
 *
 * Rule sources, in the one documented order a channel may list them:
 *   1. owner-configured pairs  - NOT BUILT: no such data exists today (no schema).
 *   2. same_category           - items of the category of the last cart line.
 *   3. popularity              - NOT BUILT as a rule: `is_popular` has no upsell use today.
 *   4. cheapest_addon          - cheapest active, in-stock items not in the cart.
 *   5. favourite               - item 26: a recognised customer's most ordered items at this store that are still
 *                                active and in stock (kiosk only; nothing without a recognised customer).
 * Each channel lists today's rules and cap, reproducing what that channel does now
 * (the per-channel behaviour that existed before this engine). A channel with no rule
 * returns an empty list (POS suggests nothing; the kiosk only a recognised customer's favourites). Channels are NOT unified.
 * Rules run in order; an item already chosen or in the cart is never repeated; the
 * channel cap applies to the combined list.
 */
class RecommendationEngine implements Recommendations
{
    /** channel => [cap, ordered rule names] */
    private const CHANNELS = [
        self::CHANNEL_AGENT => [3, [Recommendation::REASON_CHEAPEST_ADDON]],
        self::CHANNEL_STOREFRONT => [6, [Recommendation::REASON_SAME_CATEGORY]],
        self::CHANNEL_KIOSK => [6, [Recommendation::REASON_FAVOURITE]],
        self::CHANNEL_POS => [0, []],
    ];

    public function recommend(Store $store, string $channel, array $cartLines = [], ?object $customer = null): array
    {
        [$cap, $rules] = self::CHANNELS[$channel] ?? [0, []];
        if ($cap === 0) {
            return [];
        }

        $cart = $this->cartIds($cartLines);
        $out = [];
        foreach ($rules as $rule) {
            $taken = array_merge($cart, array_map(fn (Recommendation $r) => $r->itemId, $out));
            $ids = $this->runRule($rule, $store, $cart, $taken, $cap - count($out), $customer);
            foreach ($ids as $id) {
                $out[] = new Recommendation((int) $id, $rule);
            }
            if (count($out) >= $cap) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $cart
     * @param  list<int>  $taken
     * @return list<int>
     */
    private function runRule(string $rule, Store $store, array $cart, array $taken, int $limit, ?object $customer = null): array
    {
        return match ($rule) {
            Recommendation::REASON_FAVOURITE => $customer instanceof RecommendationCustomer ? $this->favourites($store, $customer, $taken, $limit) : [],
            Recommendation::REASON_CHEAPEST_ADDON => $this->cheapestAddOns($store, $taken, $limit),
            Recommendation::REASON_SAME_CATEGORY => $this->sameCategory($store, $cart, $taken, $limit),
            default => [],
        };
    }

    /**
     * @param  array<int, mixed>  $cartLines
     * @return list<int>
     */
    private function cartIds(array $cartLines): array
    {
        $ids = [];
        foreach ($cartLines as $line) {
            $id = is_array($line) ? ($line['item_id'] ?? null) : $line;
            if ($id !== null && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return Builder<Menu> */
    private function base(Store $store): Builder
    {
        return Menu::query()->where('store_id', $store->id)->where('active', '1');
    }

    /**
     * Agent rule: active, in-stock, not in the cart, cheapest first.
     *
     * @param  list<int>  $taken
     * @return list<int>
     */
    private function cheapestAddOns(Store $store, array $taken, int $limit): array
    {
        return $this->base($store)
            ->when(! empty($taken), fn ($q) => $q->whereNotIn('id', $taken))
            ->where('is_sold_out', 0)
            ->orderBy('price_minor')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')->all();
    }

    /**
     * The customer's ordered items (their order) that are still on this store's menu, active and not sold out.
     *
     * @param  list<int>  $taken
     * @return list<int>
     */
    private function favourites(Store $store, RecommendationCustomer $customer, array $taken, int $limit): array
    {
        $wanted = array_values(array_diff(array_unique(array_map('intval', $customer->orderedItemIds)), $taken));
        if ($wanted === [] || $limit < 1) {
            return [];
        }
        $live = $this->base($store)->whereIn('id', $wanted)->where('is_sold_out', 0)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_slice(array_values(array_intersect($wanted, $live)), 0, $limit);
    }

    /**
     * Same category as the LAST cart line (store-scoped), catalog (id) order; sold-out items stay, as on the item page today.
     *
     * @param  list<int>  $cart
     * @param  list<int>  $taken
     * @return list<int>
     */
    private function sameCategory(Store $store, array $cart, array $taken, int $limit): array
    {
        if ($cart === []) {
            return [];
        }
        $anchor = Menu::query()->where('store_id', $store->id)->find(end($cart));
        if ($anchor === null || $anchor->category_id === null) {
            return [];
        }

        return $this->base($store)
            ->where('category_id', $anchor->category_id)
            ->whereNotIn('id', $taken)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')->all();
    }
}
