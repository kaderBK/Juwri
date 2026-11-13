<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Retail;

use Illuminate\Support\Collection;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;

/**
 * P3-08 (REVIEW, Opus: money): the colour × size matrix on `menu_variants`, extended, not replaced (owner 10-01).
 *
 * The matrix's axes are the item's own option groups (P1-06 `modifier_groups` of kind variant — "Colour": Sand,
 * Olive; "Size": S, M, L), and each priced cell is one `menu_variants` row whose `options` is the combination as
 * {group id: option index} — the shape the storefront and the POS already read for priced combinations. A cell has
 * its own price (replacing the item's), compare-at price, SKU, GTIN, stock and image.
 *
 *  - {@see axes()}: the groups that are axes of the item's matrix (every group a cell names);
 *  - {@see find()}: the cell of a set of picks — how a cart that names options instead of a variant id is priced;
 *  - {@see generate()}: one row per missing combination of chosen groups (idempotent: existing cells, their stock and
 *    prices are never touched), the owner's "build the grid" action.
 */
final class VariantMatrix
{
    /**
     * The cells of the item (active or not) that are built on its option groups.
     *
     * @return Collection<int, MenuVariant>
     */
    public static function cells(Menu $item): Collection
    {
        $variants = $item->relationLoaded('variants') ? $item->variants : $item->variants()->get();

        return $variants->filter(fn (MenuVariant $v) => $v->cell() !== null)->values();
    }

    /**
     * Group ids that are axes of the matrix (named by at least one cell), in the item's group order.
     *
     * @return list<int>
     */
    public static function axes(Menu $item): array
    {
        $named = [];
        foreach (self::cells($item) as $cell) {
            foreach (array_keys((array) $cell->cell()) as $groupId) {
                $named[$groupId] = true;
            }
        }
        $ordered = [];
        foreach ($item->resolved_variant_options as $group) {
            if (isset($named[(int) $group->id])) {
                $ordered[] = (int) $group->id;
            }
        }

        return $ordered;
    }

    /**
     * The active cell whose combination is exactly these picks on the axes ({group id: option index}); picks of
     * other groups are ignored. Null when the picks do not name every axis or no cell has that combination.
     *
     * @param  array<int, int>  $picks
     */
    public static function find(Menu $item, array $picks): ?MenuVariant
    {
        $axes = self::axes($item);
        if ($axes === []) {
            return null;
        }
        $wanted = [];
        foreach ($axes as $groupId) {
            if (! array_key_exists($groupId, $picks)) {
                return null;
            }
            $wanted[$groupId] = (int) $picks[$groupId];
        }
        ksort($wanted);
        foreach (self::cells($item) as $cell) {
            if ($cell->active && $cell->cell() === $wanted) {
                return $cell;
            }
        }

        return null;
    }

    /**
     * Build the grid: one cell per combination of the options of `$groupIds` (groups of this item, in that order)
     * that has no cell yet. New cells start at `$basePrice` (major units; default the item's price), active, not
     * stock-tracked. Returns the new cells.
     *
     * @param  list<int>  $groupIds
     * @return list<MenuVariant>
     */
    public static function generate(Menu $item, array $groupIds, ?float $basePrice = null): array
    {
        /** @var Collection<int, MenuVariantOption> $groups */
        $groups = $item->resolved_variant_options->keyBy(fn ($g) => (int) $g->id);
        $axes = [];
        foreach (array_values(array_unique(array_map('intval', $groupIds))) as $groupId) {
            $group = $groups->get($groupId);
            if ($group === null) {
                throw new \InvalidArgumentException('Option group '.$groupId.' is not an option group of this item.');
            }
            $count = $group->modifiers->count();
            if ($count === 0) {
                continue;
            }
            $axes[$groupId] = range(0, $count - 1);
        }
        if ($axes === []) {
            return [];
        }

        $combinations = [[]];
        foreach ($axes as $groupId => $indexes) {
            $next = [];
            foreach ($combinations as $combo) {
                foreach ($indexes as $index) {
                    $next[] = $combo + [$groupId => $index];
                }
            }
            $combinations = $next;
        }
        if (count($combinations) > 500) {
            throw new \InvalidArgumentException('A variant grid can have at most 500 cells.');
        }

        $existing = [];
        foreach (self::cells($item) as $cell) {
            $existing[json_encode($cell->cell())] = true;
        }
        $position = (int) (MenuVariant::query()->where('menu_id', $item->id)->max('position') ?? 0);
        $created = [];
        foreach ($combinations as $combo) {
            ksort($combo);
            if (isset($existing[json_encode($combo)])) {
                continue;
            }
            $position++;
            $created[] = MenuVariant::create([
                'menu_id' => $item->id,
                'store_id' => $item->store_id,
                'price' => $basePrice ?? (float) $item->price,
                'options' => (object) array_map('intval', $combo),
                'active' => true,
                'position' => $position,
            ]);
            $existing[json_encode($combo)] = true;
        }

        return $created;
    }

    /**
     * A short label of a cell from the item's groups: the option labels joined ("Sand · M").
     *
     * @param  array<int, int>  $cell
     */
    public static function label(Menu $item, array $cell): string
    {
        $parts = [];
        foreach ($item->resolved_variant_options as $group) {
            if (! array_key_exists((int) $group->id, $cell)) {
                continue;
            }
            $labels = array_values((array) json_decode((string) json_encode($group->options), true));
            $label = $labels[$cell[(int) $group->id]] ?? null;
            if (is_scalar($label) && (string) $label !== '') {
                $parts[] = (string) $label;
            }
        }

        return implode(' · ', $parts);
    }
}
