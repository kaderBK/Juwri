<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services;

use App\Exceptions\PlanLimitReachedException;
use App\Models\MenuCategory;
use App\Services\Entitlements;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Brand;
use Juwri\Modules\Menu\Models\Menu;

/**
 * Menu create/update/delete persistence, shared by the owner API
 * (MenuAdminController) and the staff app (StaffAppController) so both edit the
 * menu identically. Validation rules live here too, so callers validate against
 * one source of truth.
 */
class MenuAdminService
{
    public const ITEM_RULES = [
        'category_id' => 'nullable|integer',
        'name' => 'required|string|max:180',
        'description' => 'nullable|string|max:2000',
        'price' => 'required|numeric|min:0',
        'image' => 'nullable|string|max:255',
        'type' => 'nullable|in:veg,nonveg',
        'active' => 'nullable|boolean',
        'is_sold_out' => 'nullable|boolean',
        'is_popular' => 'nullable|boolean',
        'is_vegan' => 'nullable|boolean',
        'is_halal' => 'nullable|boolean',
        'is_gluten_free' => 'nullable|boolean',
        'calories' => 'nullable|integer|min:0',
        'prep_minutes' => 'nullable|integer|min:0',
        'position' => 'nullable|integer',
        // rev/mv-surface-api P1: goods/services attributes so the REST + staff +
        // MCP menu-write paths can build a retail/pharmacy/laundry catalog, not
        // just a food menu. Every rule is nullable — a food store never sends
        // them, and the Menu columns already exist (Lane A/D/F migrations).
        'brand_id' => 'nullable|integer',
        'weight' => 'nullable|numeric|min:0',
        'dimensions' => 'nullable|array',
        'attributes' => 'nullable|array',
        'pricing_unit' => 'nullable|string|max:20',
        'plu' => 'nullable|string|max:64',                 // barcode / SKU column
        'track_inventory' => 'nullable|boolean',
        'stock_qty' => 'nullable|integer|min:0',
        'low_stock_threshold' => 'nullable|integer|min:0',
        'age_restricted' => 'nullable|boolean',            // pharmacy OTC
        'batch_no' => 'nullable|string|max:64',
        'expiry_date' => 'nullable|date',
        'is_medicine' => 'nullable|boolean',               // P1-23: pharmacy, default true
        'turnaround_hours' => 'nullable|integer|min:0',    // service items (laundry)
        'tax_rate' => 'nullable|numeric|min:0|max:100',    // P6-99b: own VAT % (null = store rate)
        'tax_category' => 'nullable|in:S,Z,E',             // P6-99b
    ];

    public const CATEGORY_RULES = [
        'name' => 'required|string|max:180',
        'image' => 'nullable|string|max:255',
        'position' => 'nullable|integer',
        'active' => 'nullable|boolean',
    ];

    /** Item rules with name/price relaxed to `sometimes` for updates. */
    public static function itemRules(bool $creating): array
    {
        $rules = self::ITEM_RULES;
        if (! $creating) {
            $rules['name'] = 'sometimes|string|max:180';
            $rules['price'] = 'sometimes|numeric|min:0';
        }

        return $rules;
    }

    public static function categoryRules(bool $creating): array
    {
        $rules = self::CATEGORY_RULES;
        if (! $creating) {
            $rules['name'] = 'sometimes|string|max:180';
        }

        return $rules;
    }

    // ── Categories ───────────────────────────────────────────────────────────

    public static function createCategory(int $storeId, array $data): MenuCategory
    {
        self::assertWithinLimit($storeId, 'category');
        $data['store_id'] = $storeId;

        return MenuCategory::create($data);
    }

    public static function updateCategory(MenuCategory $category, array $data): MenuCategory
    {
        $category->update($data);

        return $category->fresh();
    }

    /**
     * Delete a category. By default REFUSES (returns false, deletes nothing)
     * when the category still has items, so a delete can never orphan a menu —
     * the caller must move/delete the items first, or pass $force. Shared home
     * for the refuse rule so the MCP tool doesn't reimplement it.
     */
    /**
     * Delete a category, deciding what happens to its subcategories.
     *
     * The old version deleted the category's own items via the model's deleting
     * hook but said nothing about subcategories, so deleting a parent left its
     * children pointing at a row that no longer exists — an orphan the storefront
     * can never reach. That is still the platform's live source of orphaned menu
     * data (see rev/audit-residue/DATA-REPAIR-PLAN.md §3.2).
     *
     * $mode governs the subcategories, and the caller — not this service — chooses:
     *   - 'refuse'   (default): if the category has subcategories, change nothing and
     *                report it, so a caller that never opted into a destructive path
     *                cannot silently orphan children. Also preserves the original
     *                refuse-if-it-has-items guard for the leaf case.
     *   - 'cascade'  : delete the subcategories and their items too. Implies the item
     *                guard is waived — the caller asked to delete everything.
     *   - 'reparent' : promote the subcategories to top-level, then delete this one.
     *
     * The tree is two levels deep platform-wide (no grandchildren), so deleting the
     * direct children is sufficient; each child's own hook removes its items.
     *
     * $forceItems waives the has-items guard for the leaf case — the dashboard passes
     * it because its button has always been able to delete a category outright.
     *
     * @return array{ok:bool,reason:?string,subcategories:int}
     */
    public static function deleteCategory(MenuCategory $category, string $mode = 'refuse', bool $forceItems = false): array
    {
        $subCount = MenuCategory::where('parent', $category->id)->count();
        $hasItems = Menu::where('category_id', $category->id)
            ->where('store_id', $category->store_id)
            ->exists();

        if ($subCount > 0 && ! in_array($mode, ['cascade', 'reparent'], true)) {
            return ['ok' => false, 'reason' => 'has_subcategories', 'subcategories' => $subCount];
        }
        if ($hasItems && ! $forceItems && $mode !== 'cascade') {
            return ['ok' => false, 'reason' => 'has_items', 'subcategories' => $subCount];
        }

        DB::transaction(function () use ($category, $mode) {
            foreach (MenuCategory::where('parent', $category->id)->get() as $child) {
                if ($mode === 'cascade') {
                    $child->delete();          // hook removes the child's items
                } else {                       // reparent
                    $child->parent = null;
                    $child->save();
                }
            }
            $category->delete();               // hook removes this category's own items
        });

        return ['ok' => true, 'reason' => null, 'subcategories' => $subCount];
    }

    // ── Items ────────────────────────────────────────────────────────────────

    public static function createItem(int $storeId, array $data): Menu
    {
        self::assertCategoryOwned($storeId, $data['category_id'] ?? null);
        self::assertBrandOwned($storeId, $data['brand_id'] ?? null);
        self::assertWithinLimit($storeId, 'menu', $data['category_id'] ?? null);
        $data['store_id'] = $storeId;
        $data['active'] = $data['active'] ?? 1;

        return Menu::create($data);
    }

    public static function updateItem(Menu $item, array $data): Menu
    {
        self::assertCategoryOwned((int) $item->store_id, $data['category_id'] ?? null);
        self::assertBrandOwned((int) $item->store_id, $data['brand_id'] ?? null);
        $oldPrice = $item->price;
        $item->update($data);
        $fresh = $item->fresh();

        // E12: audit before/after when the PRICE changed.
        if (function_exists('log_activity') && (string) $oldPrice !== (string) $fresh->price) {
            log_activity('menu.price_updated', $item, [
                'old' => ['price' => $oldPrice],
                'new' => ['price' => $fresh->price],
            ], (int) $item->store_id);
        }

        return $fresh;
    }

    public static function toggleSoldOut(Menu $item, ?bool $force): Menu
    {
        $soldOut = $force !== null ? $force : ! $item->is_sold_out;
        $item->update(['is_sold_out' => $soldOut ? 1 : 0]);

        return $item->fresh();
    }

    /** Public array shape returned by both controllers. */
    public static function itemArray(Menu $item): array
    {
        $row = [
            'id' => $item->id,
            'category_id' => $item->category_id,
            'name' => $item->getRawOriginal('name'),
            'price' => (float) $item->price,
            'active' => (bool) $item->active,
            'is_sold_out' => (bool) $item->is_sold_out,
        ];

        // rev/mv-surface-api P1: echo the goods/OTC attrs that are set, so a
        // client that just wrote them sees them back. A food item sets none and
        // keeps the original shape.
        if ($item->brand_id !== null) {
            $row['brand_id'] = (int) $item->brand_id;
        }
        if ($item->weight !== null) {
            $row['weight'] = (float) $item->weight;
        }
        if ($item->pricing_unit !== null && $item->pricing_unit !== '') {
            $row['pricing_unit'] = $item->pricing_unit;
        }
        if ($item->track_inventory) {
            $row['track_inventory'] = true;
            $row['stock_qty'] = $item->stock_qty !== null ? (int) $item->stock_qty : null;
        }
        if ((bool) $item->age_restricted) {
            $row['age_restricted'] = true;
        }
        if ($item->batch_no !== null && $item->batch_no !== '') {
            $row['batch_no'] = $item->batch_no;
        }
        if ($item->expiry_date !== null) {
            $row['expiry_date'] = $item->expiry_date->toDateString();
        }

        return $row;
    }

    /**
     * F1-2: enforce the owner's plan limit for a menu write, at the SERVICE level
     * so every non-dashboard caller (REST API, staff app API, MCP, importers)
     * inherits it instead of each re-implementing — the audit found the REST and
     * staff paths creating past the paid limit unchecked.
     *
     * F7-9 (feature-platform gap fix): this is THE canonical counter for both
     * limits. It previously counted menu items per-RESTAURANT (mirroring the
     * MCP sibling), while the dashboard counted per-CATEGORY and
     * `Entitlements::usage()` counted per-OWNER — three different answers to
     * "how many do I have left?" for one sold limit. All three now resolve
     * through `usedFor()` below:
     *
     *   - `menu`     → items in ONE (restaurant, category) bucket. This is the
     *                  semantic the product actually documents and sells:
     *                  `FeatureMatrixController` labels `menu_limit`
     *                  "Max items per category", `admin/users/userdetails`
     *                  renders it as "N Menu Items Per Category", and the
     *                  overall ceiling is `category_limit × menu_limit`.
     *   - `category` → categories in ONE restaurant (already unanimous).
     *
     * Deliberately the LOOSEST of the three candidate semantics: converging on
     * anything stricter would retroactively block merchants who are legitimately
     * within the limit they were sold. Tightening it is a pricing decision for
     * the owner, not a bug fix (see STATUS.md F7-9 note).
     *
     * 999 is the app-wide unlimited sentinel — always resolved through
     * effective_limit() + Entitlements::isUnlimitedSentinel(), never a raw
     * comparison.
     *
     * @param  string  $type  'menu' or 'category'
     * @param  int|string|null  $categoryId  the bucket a 'menu' write lands in (null = uncategorised).
     *
     * @throws PlanLimitReachedException when the bounded limit is already met.
     */
    public static function assertWithinLimit(int $storeId, string $type, $categoryId = null): void
    {
        $owner = Store::find($storeId)?->user;
        if (! $owner) {
            // No resolvable owner → no plan to enforce against. effective_limit()
            // would return 0 here and block every write, so bail out instead.
            return;
        }

        $limit = effective_limit($type, $owner);
        if (Entitlements::isUnlimitedSentinel($limit)) {
            return;
        }

        if (self::usedFor($storeId, $type, $categoryId) >= $limit) {
            throw new PlanLimitReachedException($type);
        }
    }

    /**
     * Remaining creatable slots for a bounded type on a restaurant, or null when
     * unlimited (999 sentinel) or there is no resolvable owner to enforce against.
     * Bulk importers (POS sync, CSV) use this to skip-and-report past the cap
     * instead of throwing mid-batch. 'menu' counts the restaurant TOTAL (the
     * per-bucket count belongs to assertWithinLimit), matching the CSV importer.
     */
    public static function remainingFor(int $storeId, string $type): ?int
    {
        $ownerId = Store::where('id', $storeId)->value('user_id');
        $owner = $ownerId ? User::find($ownerId) : null;
        if (! $owner) {
            return null; // no plan to enforce against — don't block (see assertWithinLimit)
        }

        $limit = effective_limit($type, $owner);
        if (Entitlements::isUnlimitedSentinel($limit)) {
            return null;
        }

        $used = $type === 'category'
            ? (int) MenuCategory::where('store_id', $storeId)->count()
            : (int) Menu::where('store_id', $storeId)->count();

        return max(0, (int) $limit - $used);
    }

    /**
     * F7-9: the single counting implementation every entry point shares —
     * MenuAdminService (REST + staff API), the dashboard (`ManagesMenu`), the
     * MCP tools, and `Entitlements::usage()` (which maxes this over the owner's
     * non-sandbox stores/categories, since the meter needs one scalar).
     *
     * @param  int|string|null  $categoryId  only meaningful for 'menu'.
     */
    public static function usedFor(int $storeId, string $type, $categoryId = null): int
    {
        if ($type === 'category') {
            return (int) MenuCategory::where('store_id', $storeId)->count();
        }

        return (int) Menu::where('store_id', $storeId)
            ->where('category_id', $categoryId !== null && $categoryId !== '' ? (int) $categoryId : null)
            ->count();
    }

    /** A category id, if provided, must belong to the same restaurant (else 404). */
    public static function assertCategoryOwned(int $storeId, $categoryId): void
    {
        if ($categoryId) {
            MenuCategory::where('store_id', $storeId)->findOrFail($categoryId);
        }
    }

    /**
     * rev/mv-surface-api P1: a brand_id, if provided, must belong to the same
     * store (else 404) — the goods twin of assertCategoryOwned, so an API/MCP
     * client can never attach another tenant's brand to its item.
     */
    public static function assertBrandOwned(int $storeId, int|string|null $brandId): void
    {
        if ($brandId) {
            Brand::where('store_id', $storeId)->findOrFail($brandId);
        }
    }
}
