<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Storefront;

use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Contracts\QuoteItems;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;

/**
 * The storefront checkout's cart (`send-order` `items`: numeric `id`, `quantity`, `variants`, `extras[].id`,
 * `mods[{groupId, optIndex}]`, `order_note`) as OrderApiService lines, strictly inside one store. Moved out of
 * StoreController (P3-13) so the checkout, the coupon pre-flight and the quote of a theme cart read the cart in ONE
 * way: a quote of these lines prices exactly the lines the checkout places.
 *
 * Lines the checkout has always skipped silently (another store's item, sold out, out of its schedule) are left out
 * and named in `$dropped`; modifier picks are filtered like the checkout always did (see modsToOptions).
 */
final class LegacyCartLines
{
    /**
     * @param  array<int, mixed>  $items  decoded `items` (objects)
     * @param  list<string>  $dropped  names of the lines left out
     * @return list<array<string, mixed>>
     */
    public static function lines(array $items, Store $store, ?string $orderType, array &$dropped = []): array
    {
        $kept = [];
        foreach ($items as $i) {
            if (! is_object($i)) {
                continue;
            }
            $menu = app(QuoteItems::class)->byId((int) $store->id, $i->id ?? 0); // fresh outside a quote (P4-16b)
            if (! $menu || $menu->is_sold_out || ! MenuScheduleService::isAvailable($menu, null, $orderType)) {
                if ($menu) {
                    $dropped[] = (string) $menu->name;
                }

                continue;
            }
            $kept[] = [$i, $menu];
        }

        return array_map(fn (array $pair) => array_filter([
            'item_id' => (int) $pair[0]->id,
            'quantity' => (int) $pair[0]->quantity,
            'variation' => (isset($pair[0]->variants) && is_numeric($pair[0]->variants)) ? (int) $pair[0]->variants : null,
            'extras' => array_values(array_map(
                fn ($e) => (int) (is_object($e) ? ($e->id ?? 0) : $e),
                (array) ($pair[0]->extras ?? [])
            )) ?: null,
            'options' => self::modsToOptions($pair[0]->mods ?? [], $pair[1], (int) $store->id),
            'note' => ($pair[0]->order_note ?? null) ?: null,
        ], fn ($v) => $v !== null), $kept);
    }

    /**
     * H15: the storefront's modifier picks ([{groupId, optIndex}, …]) as the [{group_id, selected: [label, …]}, …]
     * shape OrderApiService::resolveOptions() validates and snapshots. Filtered the way the storefront always did
     * (resolveOptions would throw where the storefront silently dropped): a group this item does not offer, a group of
     * another store, a duplicate pick and picks beyond the group's max_select are left out; the index is resolved to
     * the option label from the group's own raw `options` (resolveOptions prices by label).
     *
     * @return list<array{group_id: int, selected: list<string>}>|null
     */
    public static function modsToOptions(mixed $mods, Menu $menu, int $storeId): ?array
    {
        $mods = is_array($mods) ? $mods : [];
        if (! $mods) {
            return null;
        }

        $allowedGroups = $menu->resolved_variant_options->keyBy('id');
        $byGroup = [];
        $groupCounts = [];
        $seen = [];
        foreach ($mods as $mod) {
            $groupId = (int) (is_object($mod) ? ($mod->groupId ?? 0) : 0);
            $optIdx = is_object($mod) && isset($mod->optIndex) ? (int) $mod->optIndex : null;
            $group = $groupId ? $allowedGroups->get($groupId) : null;
            if ($group && ! self::groupBelongsToStore($group, $storeId)) {
                $group = null;
            }
            if (! $group || $optIdx === null) {
                continue;
            }
            $dedupKey = $groupId.':'.$optIdx;
            if (isset($seen[$dedupKey])) {
                continue;
            }
            $seen[$dedupKey] = true;
            // P6-310: null/0 = unlimited (same as OrderApiService); a pick past a real max is refused, not dropped.
            $groupMax = (int) ($group->max_select ?? 0);
            $groupCounts[$groupId] = ($groupCounts[$groupId] ?? 0) + 1;
            if ($groupMax > 0 && $groupCounts[$groupId] > $groupMax) {
                throw ValidationException::withMessages(['options' => "Choose at most {$groupMax} for “{$group->title}”."]);
            }
            $opts = $group->getOriginal('options');
            $opts = is_string($opts) ? json_decode($opts, true) : (array) $opts;
            if (! array_key_exists($optIdx, (array) $opts)) {
                continue;
            }
            $byGroup[$groupId] ??= ['group_id' => $groupId, 'selected' => []];
            $byGroup[$groupId]['selected'][] = $opts[$optIdx];
        }

        return array_values($byGroup) ?: null;
    }

    public static function groupBelongsToStore(MenuVariantOption $group, int $storeId): bool
    {
        if ((int) ($group->store_id ?? 0) === $storeId) {
            return true;
        }

        if ($group->store_id === null && $group->menu_id) {
            return (int) (Menu::where('id', $group->menu_id)->value('store_id') ?? 0) === $storeId;
        }

        return false;
    }
}
