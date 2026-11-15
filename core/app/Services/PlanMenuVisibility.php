<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\MenuCategory;
use Illuminate\Support\Collection;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;

/**
 * The READ-side twin of the plan limit — what a store's menu SHOWS.
 *
 * The plan caps what a store may HOLD (write side: the dashboard, the REST and
 * staff APIs, the MCP tools, the CSV importer and the AI generator all refuse to
 * go past it). This service caps what a store PUBLISHES: a store that already
 * holds more than its plan covers — a menu built during an unlimited trial, or
 * written by a bulk path — keeps every row, but only the rows the plan pays for
 * reach customers.
 *
 * Nothing is deleted and nothing is written here: the cap is read from the
 * owner's CURRENT plan on every request, so an upgrade reveals the rest
 * immediately, with no repair step.
 *
 * The semantics mirror the write-side counters (MenuAdminService::usedFor):
 *   - `category_limit` counts the store's CATEGORY ROWS (a subcategory is a row
 *     of its own), and
 *   - `menu_limit` counts the items in ONE category row.
 * So a store on "1 category / 10 items" publishes 1 category with 10 items.
 */
class PlanMenuVisibility
{
    /**
     * The owner's published-menu caps.
     *
     * @return array{category: ?int, item: ?int} null = unlimited
     */
    public static function caps(?User $owner): array
    {
        if (! $owner) {
            return ['category' => null, 'item' => null];
        }

        return [
            'category' => self::limit($owner, 'category'),
            'item' => self::limit($owner, 'menu'),
        ];
    }

    /**
     * One cap, resolved the way the write path resolves it (effective_limit +
     * the 999 unlimited sentinel). NULL = unlimited.
     */
    public static function limit(User $owner, string $type): ?int
    {
        try {
            $limit = effective_limit($type, $owner);
        } catch (\Throwable) {
            return null; // fail OPEN — a lookup error must never hide a store's menu
        }

        if (Entitlements::isUnlimitedSentinel($limit)) {
            return null;
        }

        $limit = (int) $limit;

        return $limit > 0 ? $limit : 0;
    }

    /**
     * Cap a store's published menu to its owner's plan — nested shape (the web
     * storefront: top-level categories, each with its subcategories).
     *
     * Category rows are kept in display order (position, then id) up to the
     * category cap — a top-level category first, then its subcategories, so a
     * kept subcategory always has a kept parent. Inside every kept category row
     * the items are kept in the same order up to the per-category item cap;
     * items filed under a category beyond the cap are hidden too.
     *
     * @param  Collection<int, Menu>  $items
     * @param  Collection<int, MenuCategory>  $categories  top-level categories (subcategories eager-loaded)
     * @return array{0: Collection<int, Menu>, 1: Collection<int, MenuCategory>}
     */
    public static function apply(Collection $items, Collection $categories, ?User $owner): array
    {
        $caps = self::caps($owner);

        if ($caps['category'] === null && $caps['item'] === null) {
            return [$items, $categories];
        }

        // Flatten to rows in display order: a top-level category, then its
        // subcategories, then the next top-level category.
        $rows = collect();
        foreach (self::ordered($categories) as $category) {
            $rows->push($category);
            foreach (self::subcategoriesOf($category) as $sub) {
                $rows->push($sub);
            }
        }

        $keptRows = self::capRows($rows, $caps['category']);
        $visibleIds = $keptRows->map(fn ($c) => (int) $c->id)->all();

        // Rebuild the nested shape: a top-level category survives only when it
        // is itself a kept row, and its subcategories are the kept sub-rows.
        $keptCategories = collect();
        foreach (self::ordered($categories) as $category) {
            if (! in_array((int) $category->id, $visibleIds, true)) {
                continue;
            }

            $keptSubs = collect();
            foreach (self::subcategoriesOf($category) as $sub) {
                if (in_array((int) $sub->id, $visibleIds, true)) {
                    $keptSubs->push($sub);
                }
            }

            // The theme walks $category->subcategories, so the dropped rows must
            // leave the relation too — otherwise a hidden subcategory still shows.
            $category->setRelation('subcategories', $keptSubs);
            $keptCategories->push($category);
        }

        return [self::capItems($items, $visibleIds, $caps['item']), $keptCategories];
    }

    /**
     * Cap a store's published menu to its owner's plan — flat shape (the public
     * menu API: every category row of the store in one list).
     *
     * @param  Collection<int, Menu>  $items
     * @param  Collection<int, MenuCategory>  $rows  every category row of the store
     * @return array{0: Collection<int, Menu>, 1: Collection<int, MenuCategory>}
     */
    public static function applyFlat(Collection $items, Collection $rows, ?User $owner): array
    {
        $caps = self::caps($owner);

        if ($caps['category'] === null && $caps['item'] === null) {
            return [$items, $rows];
        }

        $keptRows = self::capRows($rows, $caps['category']);
        $visibleIds = $keptRows->map(fn ($c) => (int) $c->id)->all();

        return [self::capItems($items, $visibleIds, $caps['item']), $keptRows];
    }

    /**
     * Keep the first $cap category rows, in parent-then-children display order.
     *
     * @param  Collection<int, MenuCategory>  $rows
     * @return Collection<int, MenuCategory>
     */
    private static function capRows(Collection $rows, ?int $cap): Collection
    {
        $ordered = self::orderedTree($rows);

        return $cap === null ? $ordered : $ordered->take($cap)->values();
    }

    /**
     * Order category rows parent-then-children: top-level rows in display order,
     * each followed immediately by its subcategories in display order.
     *
     * @param  Collection<int, MenuCategory>  $rows
     * @return Collection<int, MenuCategory>
     */
    public static function orderedTree(Collection $rows): Collection
    {
        $ordered = self::ordered($rows);
        $ids = $ordered->map(fn ($c) => (int) $c->id)->all();
        $topLevel = $ordered->reject(fn ($c) => $c->parent && in_array((int) $c->parent, $ids, true));
        $byParent = $ordered
            ->filter(fn ($c) => $c->parent && in_array((int) $c->parent, $ids, true))
            ->groupBy(fn ($c) => (int) $c->parent);

        $tree = collect();
        foreach ($topLevel as $cat) {
            $tree->push($cat);
            foreach ($byParent->get((int) $cat->id, collect()) as $sub) {
                $tree->push($sub);
            }
        }

        return $tree->values();
    }

    /**
     * Keep at most $cap items in each kept category row, in display order.
     *
     * Uncategorised items (no category row) belong to no category, so the
     * category cap cannot hide them — they are kept, still capped per bucket.
     *
     * @param  Collection<int, Menu>  $items
     * @param  list<int>  $visibleIds
     * @return Collection<int, Menu>
     */
    private static function capItems(Collection $items, array $visibleIds, ?int $cap): Collection
    {
        $perCategory = [];
        $kept = collect();

        foreach ($items->sortBy(fn ($m) => [(int) ($m->position ?? 0), (int) $m->id]) as $item) {
            $categoryId = (int) $item->category_id;

            if ($categoryId > 0 && ! in_array($categoryId, $visibleIds, true)) {
                continue;
            }

            if ($cap !== null && ($perCategory[$categoryId] ?? 0) >= $cap) {
                continue;
            }

            $perCategory[$categoryId] = ($perCategory[$categoryId] ?? 0) + 1;
            $kept->push($item);
        }

        return $kept;
    }

    /**
     * @param  Collection<int, MenuCategory>  $categories
     * @return Collection<int, MenuCategory>
     */
    private static function ordered(Collection $categories): Collection
    {
        return $categories
            ->sortBy(fn ($c) => [(int) ($c->position ?? 0), (int) $c->id])
            ->values();
    }

    /** @return Collection<int, MenuCategory> */
    private static function subcategoriesOf(MenuCategory $category): Collection
    {
        if (! $category->relationLoaded('subcategories')) {
            return collect();
        }

        /** @var Collection<int, MenuCategory> $subs */
        $subs = $category->getRelation('subcategories');

        return self::ordered($subs);
    }
}
