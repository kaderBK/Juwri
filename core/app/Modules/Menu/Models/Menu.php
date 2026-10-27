<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use App\Models\Concerns\BustsMenuCache;
use App\Models\Concerns\SyncsAggregatorAvailability;
use App\Models\Concerns\TranslatesForStorefront;
use App\Models\Ingredient;
use App\Models\KitchenStation;
use App\Models\MenuCategory;
use App\Models\MenuReview;
use App\Models\RecipeItem;
use App\Models\StoreOption;
use App\Models\VehicleFitment;
use App\Services\Storefront\StorefrontMenuCache;
use App\Support\SearchTerm;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Concerns\AppliesTimeWindow;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Search\StoreSearchable;
use Juwri\Modules\Menu\Models\Concerns\FiresMenuWebhook;
use Juwri\Modules\Menu\Observers\MenuObserver;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Juwri\Modules\Menu\Services\Retail\StockAlerts;
use Laravel\Scout\Attributes\SearchUsingFullText;

/**
 * P1-05 money (*_minor + currency_code; major attributes via HasMinorMoney):
 *
 * @property float|null $price
 * @property int|null $price_minor
 * @property string|float|null $cost
 * @property int|null $cost_minor
 * @property string|null $currency_code
 * @property string|null $public_id UUIDv7 public id (J25-1)
 */
#[ObservedBy([MenuObserver::class])]
class Menu extends Model implements MinorMoneyModel
{
    /* PARITY-RUN T5: shared day+time window arithmetic (also on MenuCategory). */
    use AppliesTimeWindow;
    use BelongsToAccount;

    /* P5: bust the cached storefront menu payload on any write. */
    use BustsMenuCache;
    use FiresMenuWebhook;
    use HasFactory;
    use HasMinorMoney;
    use HasUuids;

    /* D4: undo/trash. SoftDeletes hides a deleted item (excluded from the
       storefront, limits and lists) but keeps the row + its images for 30 days
       so a fat-fingered bulk delete is reversible. Image cleanup moves to
       forceDeleted (below) so files survive a soft delete. */
    use SoftDeletes;

    /* P1-17: Scout database search (owner dashboard palette); see toSearchableArray(). */
    use StoreSearchable;

    /* PARITY-RUN T7: 86-toggle → Zomato/Swiggy stock sync (best-effort, guarded). */
    use SyncsAggregatorAvailability;
    use TranslatesForStorefront;

    public $table = 'items';

    public $timestamps = false;

    /** MCP-expansion P0-4: entity label for the menu.updated webhook. */
    protected string $menuWebhookEntity = 'item';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>|bool
     */
    protected $guarded = [];

    /**
     * P1-17: the searched fields — the item name only (what the owner dashboard searched before Scout). Full-text
     * index items_name_fulltext (MySQL FULLTEXT, PostgreSQL GIN on to_tsvector('simple', name)).
     *
     * @return array<string, mixed>
     */
    #[SearchUsingFullText(['name'], ['language' => 'simple'])]
    public function toSearchableArray(): array
    {
        return ['name' => $this->name];
    }

    /**
     * P6-295: searched columns with a stored normalised counterpart (items.name → items.name_search). The database
     * search engine matches the normalised term against it, keeping the exact LIKE on name as a fallback.
     *
     * @return array<string, string>
     */
    public function normalizedSearchColumns(): array
    {
        return ['name' => 'name_search'];
    }

    /**
     * P1-05 (J25-4): money columns are `*_minor` integers in `currency_code`; `price` (number, as the old float cast)
     * and `cost` (store-precision string, as the old StoreMoney cast) stay readable/writable for compatibility.
     *
     * @var array<string, string>
     */
    protected array $minorMoney = ['price' => 'price_minor', 'cost' => 'cost_minor,store'];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'translations' => 'object',
        'dietary_tags' => 'array',
        'is_vegan' => 'boolean',
        'is_halal' => 'boolean',
        'is_gluten_free' => 'boolean',
        'is_popular' => 'boolean',
        'is_sold_out' => 'boolean',
        'auto_86_recipe' => 'boolean', // D10: was this sold-out flip recipe-driven?
        'is_daily_special' => 'boolean',
        'gallery_images' => 'array',
        'track_inventory' => 'boolean',
        'stock_qty' => 'integer',
        'low_stock_threshold' => 'integer',
        // rev/multi-vertical Lane A1: hybrid attribute schema (DECISIONS A1).
        'brand_id' => 'integer',
        'weight' => 'float',
        'dimensions' => 'array',
        'attributes' => 'array',
        // rev/multi-vertical Lane D1: service item model (DECISIONS A9).
        'turnaround_hours' => 'integer',
        // rev/multi-vertical Lane F1/F2: OTC-only pharmacy compliance (DECISIONS D3/A7).
        'age_restricted' => 'boolean',
        'expiry_date' => 'date',
        'is_medicine' => 'boolean', // P1-23: pharmacy stores — false for cosmetics, baby care, …
    ];

    /**
     * #78 / H5: deduct simple-mode stock for one order line.
     *
     * ONE conditional UPDATE — `where stock_qty >= $qty` + `decrement` — so two
     * concurrent orders cannot both pass the check. The previous
     * `lockForUpdate()->find()` + read-modify-write was a no-op: under autocommit
     * InnoDB drops the row lock at statement end, and none of the four call sites
     * (StoreController::submitOrder, OrderApiService::persistLines,
     * ComboService::deductComponents, PosService::applyDeferredDeduction) opens a
     * transaction around it.
     *
     * @return bool true when the full quantity was deducted; false when the row
     *              did not have it. On false the full qty is still deducted so the
     *              row goes NEGATIVE (truthful) rather than clamping to 0 — clamping
     *              lost the shortfall and let a later void mint phantom units — and
     *              the row is flagged sold out and the oversell recorded so an
     *              operator can see it. Callers may ignore the return (all four do).
     */
    public static function deductStock(int $menuId, int $qty, ?Menu $known = null): bool
    {
        $qty = max(0, $qty);
        if (self::untracked($known, $menuId)) {
            return true; // P6-148: the order already read this row — an untracked item takes no stock
        }
        // Unlocked read: it only decides whether this item is tracked at all. The
        // race is closed by the WHERE on the UPDATE below, not by this SELECT.
        $item = static::find($menuId);
        if (! $item || ! $item->track_inventory || $item->stock_qty === null || $qty === 0) {
            return true;
        }

        $ok = static::whereKey($menuId)
            ->where('stock_qty', '>=', $qty)
            ->decrement('stock_qty', $qty) > 0;

        if (! $ok) {
            // Not enough stock at commit time — a genuine oversell. Deduct the full
            // quantity anyway so stock goes NEGATIVE (truthful) instead of clamping to
            // 0. Clamping lost the shortfall, so a later void restored the full ordered
            // qty and minted phantom units (deduct 3-of-5 → 0, restore +5 → 5). Full
            // deduct keeps deduct and restore exactly symmetric.
            static::whereKey($menuId)->decrement('stock_qty', $qty);
            self::recordStockOversell($item, $qty);
        }

        self::afterStockTaken($item);

        return $ok;
    }

    /**
     * P3-08 (REVIEW, Opus: stock race): take `$qty` units for a CUSTOMER order (online storefront, headless API, goods
     * checkout) or refuse — never oversell. The same single conditional UPDATE as {@see deductStock()} (`where
     * stock_qty >= $qty`): MySQL/InnoDB and PostgreSQL lock the row for the UPDATE and re-check the condition against
     * the latest committed value, so of two shoppers racing for the last unit exactly one gets it; the other gets
     * false and its order is rolled back with `out_of_stock`. Staff and partner channels keep deductStock() (an
     * already-sold POS sale or aggregator order is recorded, never refused).
     *
     * @return bool false = not enough stock (nothing was taken)
     */
    public static function reserveStock(int $menuId, int $qty, ?Menu $known = null): bool
    {
        $qty = max(0, $qty);
        if (self::untracked($known, $menuId)) {
            return true; // P6-148: the order already read this row — an untracked item takes no stock
        }
        $item = static::find($menuId);
        if (! $item || ! $item->track_inventory || $item->stock_qty === null || $qty === 0) {
            return true;
        }

        $ok = static::whereKey($menuId)
            ->where('stock_qty', '>=', $qty)
            ->decrement('stock_qty', $qty) > 0;
        if (! $ok) {
            return false;
        }

        self::afterStockTaken($item);

        return true;
    }

    /** The side effects of a stock decrement done with the query builder (no model events fire). */
    /**
     * P6-148: the order path has just read the item (in this request, inside its transaction); when that row says the
     * item tracks no stock (track_inventory off or stock_qty null — what the unlocked read below decides), the read is
     * not repeated. A tracked item, or no row given, takes the read exactly as before.
     */
    private static function untracked(?Menu $known, int $menuId): bool
    {
        return $known !== null && (int) $known->getKey() === $menuId
            && (! $known->track_inventory || $known->stock_qty === null);
    }

    private static function afterStockTaken(Menu $item): void
    {
        $item = $item->fresh();

        /* A query-builder decrement fires NO Eloquent model events, so the two
           side effects the old $item->update() carried have to be re-fired by
           hand or they silently disappear:
             • BustsMenuCache (on `saved`) — without it the storefront keeps
               serving the pre-deduction menu payload for up to menu_cache_ttl.
             • SyncsAggregatorAvailability (on `updated` + wasChanged('is_sold_out'))
               — without it Zomato/Swiggy keep selling a sold-out item, which is
               the partner-cancellation penalty PARITY-RUN T7 exists to prevent.
           The sold-out flip below is therefore deliberately a MODEL write. */
        StorefrontMenuCache::bust($item?->menuCacheRestaurantId());

        if ($item && (int) $item->stock_qty <= 0 && ! $item->is_sold_out) {
            // Order-driven stock write — suppress menu.updated (order.* already
            // covers it). Deliberate menu edits still fire the webhook.
            self::withoutMenuWebhook(fn () => $item->update(['is_sold_out' => true]));
        }
    }

    /**
     * H5 UI: an order was taken for stock the row did not have. Advisory counter
     * for the dashboard Inventory banner + a structured log line for ops. The
     * counter read-modify-write is NOT atomic and does not need to be — it is a
     * display gauge, never money, and it only runs on the (rare) oversell branch.
     */
    private static function recordStockOversell(Menu $item, int $qty): void
    {
        $storeId = (int) ($item->store_id ?? 0);
        if ($storeId <= 0) {
            return;
        }
        try {
            Log::warning('stock.oversell', [
                'store_id' => $storeId,
                'menu_id' => (int) $item->id,
                'requested' => $qty,
                'available' => (int) $item->stock_qty,
            ]);
            $count = (int) (StoreOption::getStoreOption($storeId, 'stock_oversell_count', 0) ?: 0);
            StoreOption::updateStoreOption($storeId, 'stock_oversell_count', $count + 1);
            StoreOption::updateStoreOption($storeId, 'stock_oversell_last_at', now()->toDateTimeString());
        } catch (\Throwable $e) {
            // an inventory gauge must never be able to fail an order
        }
    }

    /**
     * Restore stock after a voided/edited order line — exact mirror of
     * deductStock: same track_inventory/null guards, one atomic statement, and
     * coming back above zero clears the sold-out flag the deduction set.
     */
    public static function restoreStock(int $menuId, int $qty): void
    {
        $qty = max(0, $qty);
        $item = static::find($menuId);
        if (! $item || ! $item->track_inventory || $item->stock_qty === null || $qty === 0) {
            return;
        }

        // A sold-out flag is STOCK-driven only if stock had actually run out; a flag set
        // while stock was still on hand is a MANUAL 86 the owner chose.
        $wasDepleted = (int) $item->stock_qty <= 0;

        static::whereKey($menuId)->increment('stock_qty', $qty);

        $item = $item->fresh();
        StorefrontMenuCache::bust($item?->menuCacheRestaurantId());

        // Only auto-clear a stock-driven sold-out (this restore lifted stock from <=0 back
        // above 0). A manual 86 on an item that still had stock must NOT be silently
        // un-86'd by a later void/restore.
        if ($item && $wasDepleted && (int) $item->stock_qty > 0 && $item->is_sold_out) {
            self::withoutMenuWebhook(fn () => $item->update(['is_sold_out' => false]));
        }
        if ($item && $wasDepleted && (int) $item->stock_qty > 0) {
            // P3-08 / N5: back in stock — the waiting back-in-stock requests go out (after commit).
            StockAlerts::restocked((int) $item->id, null);
        }
    }

    /**
     * D10 — recipe-depletion auto-86. An ingredient just hit ≤ 0, so every menu item
     * whose recipe needs it is flipped sold-out (reusing the exact stock-driven idiom:
     * cache bust + aggregator sync via a MODEL write, webhook suppressed). Only items
     * NOT already sold-out are touched, and each is stamped `auto_86_recipe` so a later
     * restock can un-86 exactly these — never a manual 86. Guarded by RecipeItem's
     * existence; a no-op when the option is off (the caller gates on `recipe_auto_86`).
     */
    public static function auto86ForDepletedIngredient(int $ingredientId): void
    {
        if (! class_exists(RecipeItem::class)) {
            return;
        }
        $menuIds = RecipeItem::where('ingredient_id', $ingredientId)->pluck('menu_id')->unique();
        foreach (static::whereIn('id', $menuIds)->where('is_sold_out', false)->get() as $item) {
            self::withoutMenuWebhook(fn () => $item->update(['is_sold_out' => true, 'auto_86_recipe' => true]));
            StorefrontMenuCache::bust($item->menuCacheRestaurantId());
        }
    }

    /**
     * D10 — the restock counterpart. An ingredient came back above 0: un-86 the items
     * this engine 86'd (`auto_86_recipe` set) whose recipe is now fully satisfiable
     * (no other required ingredient still ≤ 0). A manual sold-out (auto_86_recipe = 0)
     * is never touched — the mirror of restoreStock's stock-driven-only rule.
     */
    public static function clearRecipe86ForIngredient(int $ingredientId): void
    {
        if (! class_exists(RecipeItem::class)) {
            return;
        }
        $menuIds = RecipeItem::where('ingredient_id', $ingredientId)->pluck('menu_id')->unique();
        foreach (static::whereIn('id', $menuIds)->where('is_sold_out', true)->where('auto_86_recipe', true)->get() as $item) {
            $needed = RecipeItem::where('menu_id', $item->id)->pluck('ingredient_id')->unique();
            $stillShort = Ingredient::whereIn('id', $needed)->where('current_stock', '<=', 0)->exists();
            if (! $stillShort) {
                self::withoutMenuWebhook(fn () => $item->update(['is_sold_out' => false, 'auto_86_recipe' => false]));
                StorefrontMenuCache::bust($item->menuCacheRestaurantId());
            }
        }
    }

    /**
     * `menu.active` is enum('0','1') on MySQL, and MySQL reads a NUMERIC value as
     * the enum INDEX, not the label: index 1 is '0', so `['active' => 1]` silently
     * stored '0' (item unpublished), and a numeric 0 is the invalid-value index —
     * a hard "Data truncated" error under strict mode. sqlite has no enum, stores
     * the literal, and hid both in the test suite (the nightly MySQL lane caught
     * it). Normalize every Eloquent write to the string label. NOTE: query-builder
     * bulk `->update()` bypasses mutators — those call sites must pass '0'/'1'
     * strings themselves.
     */
    public function setActiveAttribute(bool|int|string|null $value): void
    {
        $this->attributes['active'] = $value === null ? null : (string) (int) (bool) $value;
    }

    /**
     * rev/multi-vertical Lane A6 (O-BARCODE resolve): `menu.plu` already IS the
     * item barcode everywhere it's read (POS-V2 T1-6's scanner wedge reads
     * `$m->plu` directly, see PosController) — so `barcode` is an ALIAS onto
     * `plu`, not a duplicate column. This keeps one source of truth and means
     * every existing POS/aggregator caller of `plu` needs zero changes while
     * the retail item form can label the same field "Barcode".
     */
    public function getBarcodeAttribute(): ?string
    {
        return $this->attributes['plu'] ?? null;
    }

    public function setBarcodeAttribute(?string $value): void
    {
        $this->attributes['plu'] = $value;
    }

    /**
     * Find an item by its barcode (aliased to `plu`) within one store. Used by
     * BarcodeResolver so a POS-style scan also works for retail items created
     * through the Lane A catalog UI.
     */
    public static function findByBarcode(string $code, int $storeId): ?self
    {
        return static::where('store_id', $storeId)->where('plu', $code)->first();
    }

    /** The item name in the storefront visitor's language (its `title` translation). */
    public function getNameAttribute($value): mixed
    {
        return $this->storefrontTranslation('title') ?? $value;
    }

    /** P6-295: keep the stored normalised search column in step with the raw name. */
    public function setNameAttribute(?string $value): void
    {
        $this->attributes['name'] = $value;
        $this->attributes['name_search'] = $value === null ? null : SearchTerm::normalize($value);
    }

    /** The item description in the storefront visitor's language. */
    public function getDescriptionAttribute($value): mixed
    {
        return $this->storefrontTranslation('description') ?? $value;
    }

    /**
     * Relationships
     */
    /** @return HasMany<MenuVariantOption, $this> */
    public function variantOptions(): HasMany
    {
        return $this->hasMany(MenuVariantOption::class)->orderBy('position');
    }

    /** @return HasMany<MenuVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(MenuVariant::class)->orderBy('position');
    }

    /** @return HasMany<MenuExtra, $this> */
    public function extras(): HasMany
    {
        return $this->hasMany(MenuExtra::class)->orderBy('position');
    }

    /**
     * Storefront Liquid surface: approved reviews for this item, newest first.
     * Approved-only — pending/rejected reviews must never reach a theme (see
     * ReviewsDrop). Eager-loaded by the storefront render site
     * (StoreController::publicView); never queried lazily inside a Drop.
     *
     * @return HasMany<MenuReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(MenuReview::class)->where('status', 'approved')->orderByDesc('created_at');
    }

    /**
     * rev/multi-vertical Lane E1 — vehicle compatibility rows (car-parts only;
     * empty for every other vertical).
     *
     * @return HasMany<VehicleFitment, $this>
     */
    public function fitments(): HasMany
    {
        return $this->hasMany(VehicleFitment::class, 'menu_id')->orderBy('year');
    }

    /**
     * Extras attached via the library pivot (per-item + global), with overrides.
     *
     * @return BelongsToMany<MenuExtra, $this, MenuItemExtraPivot>
     */
    public function attachedExtras(): BelongsToMany
    {
        // P1-06: item_modifier_group rows that name one add-on (modifier_id set)
        return $this->belongsToMany(MenuExtra::class, 'item_modifier_group', 'menu_id', 'modifier_id')
            ->wherePivotNotNull('modifier_id')
            ->using(MenuItemExtraPivot::class)
            ->withPivot(['position', 'price_override_minor', 'currency_code', 'active'])
            ->orderByPivot('position', 'asc');
    }

    /**
     * Variant-option groups attached via the library pivot (per-item + global), with rule overrides.
     *
     * @return BelongsToMany<MenuVariantOption, $this, MenuItemVariantOptionPivot>
     */
    public function attachedVariantOptions(): BelongsToMany
    {
        // P1-06: item_modifier_group rows that attach a whole group (modifier_id null)
        return $this->belongsToMany(MenuVariantOption::class, 'item_modifier_group', 'menu_id', 'modifier_group_id')
            ->wherePivotNull('modifier_id')
            ->using(MenuItemVariantOptionPivot::class)
            ->withPivot(['position', 'required', 'min_select', 'max_select', 'active'])
            ->orderByPivot('position', 'asc');
    }

    /**
     * Resolved extras for this item: pivot attachments (per-item + global) unioned with any
     * legacy hasMany rows not yet in the pivot. Per-attach price override is applied to `price`.
     * Returns a Collection of MenuExtra.
     *
     * MONEY: `item_modifier_group.price_override` (was menu_item_extra) is deliberately UNCAST, and must stay that way.
     * It is laundered into `$e->price` below, and MenuExtra's `price` is a MinorMoney attribute in the
     * `store` format — so the override lands on the store's own precision (USD '0.28',
     * OMR '0.275') without a cast of its own. Do NOT "fix" the pivot by casting it: a pivot
     * row has no `store_id`, so a store-precision cast there would fall back to
     * 2 decimals and round a 3-decimal store's override BEFORE it ever reaches `price` —
     * turning a correct 0.275 into 0.28. The raw pivot value is never rendered anywhere; every
     * consumer (PublicController, PosController, CatalogExport, AgentToolsService, all 16
     * themes) reads `$e->price`.
     */
    public function getResolvedExtrasAttribute()
    {
        // N+1: when the caller eager-loads attachedExtras/extras (POS, public API,
        // catalog export), resolve from the loaded relations in PHP instead of firing
        // two queries per item. Not loaded → query exactly as before (byte-identical).
        $attached = ($this->relationLoaded('attachedExtras')
            ? $this->attachedExtras->filter(fn ($e) => (bool) ($e->pivot->active ?? false))->values()
            : $this->attachedExtras()->where('item_modifier_group.active', true)->get())
            ->each(function ($e) {
                if ($e->pivot->price_override !== null) {
                    $e->price = $e->pivot->price_override;
                }
            });

        $attachedIds = $attached->pluck('id')->all();
        $legacy = $this->relationLoaded('extras')
            ? $this->extras->whereNotIn('id', $attachedIds)->values()
            : $this->extras()->whereNotIn('id', $attachedIds)->get();

        return $attached->concat($legacy)->values();
    }

    /**
     * Resolved variant-option groups: pivot attachments unioned with legacy rows. Selection
     * rules use the pivot override when set, else the library group's own rule.
     * Returns a Collection of MenuVariantOption.
     */
    public function getResolvedVariantOptionsAttribute()
    {
        // N+1: prefer eager-loaded relations when the caller loaded them (see
        // getResolvedExtrasAttribute); not loaded → query exactly as before.
        $attached = ($this->relationLoaded('attachedVariantOptions')
            ? $this->attachedVariantOptions->filter(fn ($o) => (bool) ($o->pivot->active ?? false))->values()
            : $this->attachedVariantOptions()->where('item_modifier_group.active', true)->get())
            ->each(function ($o) {
                if ($o->pivot->required !== null) {
                    $o->required = (bool) $o->pivot->required;
                }
                if ($o->pivot->min_select !== null) {
                    $o->min_select = (int) $o->pivot->min_select;
                }
                if ($o->pivot->max_select !== null) {
                    $o->max_select = (int) $o->pivot->max_select;
                }
            });

        $attachedIds = $attached->pluck('id')->all();
        $legacy = $this->relationLoaded('variantOptions')
            ? $this->variantOptions->whereNotIn('id', $attachedIds)->values()
            : $this->variantOptions()->whereNotIn('id', $attachedIds)->get();

        return $attached->concat($legacy)->values();
    }

    /** @return BelongsTo<MenuCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    /**
     * rev/multi-vertical Lane A3: the per-store brand this item belongs to
     * (retail/cosmetic/clothing/carparts/pharmacy). Null for restaurants and
     * for any item that hasn't picked one.
     *
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * The owning restaurant (Post). Used by the Catalog MCP cross-store search.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** P5: this item's store. */
    public function menuCacheRestaurantId(): ?int
    {
        return $this->store_id ? (int) $this->store_id : null;
    }

    public function recipeItems()
    {
        return $this->hasMany(RecipeItem::class, 'menu_id');
    }

    public function station()
    {
        return $this->belongsTo(KitchenStation::class, 'station_id');
    }

    /** @return HasMany<BranchMenu, $this> */
    public function branchSettings(): HasMany
    {
        return $this->hasMany(BranchMenu::class, 'menu_id');
    }

    /** Effective price at a branch — the override if set, else the base price. */
    public function priceForBranch($branchId): float
    {
        $base = (float) $this->price;
        if (! $branchId) {
            return $base;
        }
        $setting = $this->relationLoaded('branchSettings')
            ? $this->branchSettings->firstWhere('branch_id', $branchId)
            : BranchMenu::where('menu_id', $this->id)->where('branch_id', $branchId)->first();

        return ($setting && $setting->price !== null) ? (float) $setting->price : $base;
    }

    /** Is this item available at a branch? Absence of a row = available. */
    public function availableAtBranch($branchId): bool
    {
        if (! $branchId) {
            return true;
        }
        $setting = $this->relationLoaded('branchSettings')
            ? $this->branchSettings->firstWhere('branch_id', $branchId)
            : BranchMenu::where('menu_id', $this->id)->where('branch_id', $branchId)->first();

        return $setting ? (bool) $setting->available : true;
    }

    /**
     * P6-49: the merged cast map of this instance, rebuilt only when `$casts` itself changed. Eloquent rebuilds it with an
     * array_merge on EVERY attribute read (hasCast / getCasts), and a 2,000-item storefront page reads tens of thousands of
     * attributes (it was the largest single cost of the render). The private properties are not serialized (the cached
     * collection stays the same size) and a changed `$casts` (mergeCasts / setCasts) fails the identity check below, so a
     * cast added later is always seen.
     *
     * @var array<string, mixed>|null
     */
    private ?array $castsSource = null;

    /** @var array<string, mixed> */
    private array $castsMerged = [];

    public function getCasts()
    {
        if ($this->castsSource === null || $this->castsSource !== $this->casts) {
            $this->castsMerged = parent::getCasts();
            $this->castsSource = $this->casts;
        }

        return $this->castsMerged;
    }

    /**
     * P6-49: what MenuScheduleService::decorate() writes on an item for one render (never persisted). Direct writes: none
     * of these four names has a cast, mutator or accessor, and decorate() runs once per item of the store per request,
     * where the general setAttribute() path (cast and mutator lookups) was a measurable share of a 2,000-item render.
     */
    public function setDecoration(bool $available, ?string $availableFromLabel, mixed $originalPrice, ?string $scheduleLabel): void
    {
        $this->attributes['available_now'] = $available;
        $this->attributes['available_from_label'] = $availableFromLabel;
        $this->attributes['original_price'] = $originalPrice;
        $this->attributes['schedule_label'] = $scheduleLabel;
    }

    /**
     * Is this item orderable right now, given its scheduled-availability window?
     * Honors available_days (CSV of mon..sun; empty = every day) and the
     * available_from/available_to time window (HH:MM; supports overnight
     * windows like 22:00-02:00). Both ends of the time window must be set for
     * it to gate. This is the single source of truth themes and the order path
     * share, so what a diner sees matches what checkout will accept.
     *
     * PARITY-RUN T5: the window arithmetic moved to the shared
     * {@see AppliesTimeWindow} trait (MenuCategory and
     * PriceSchedule speak the same vocabulary now). Signature and behaviour are
     * unchanged — this checks the ITEM's own window only. For the full picture
     * (category window + order-type scope + the superadmin kill-switch) use
     * {@see MenuScheduleService::isAvailable()}.
     */
    public function isAvailableNow(?string $tz = null, ?string $orderType = null): bool
    {
        return $this->windowOpenNow(null, $tz ?: config('app.timezone', 'UTC'), $orderType);
    }

    /** Total ingredient cost to make one portion (sum of recipe line costs). */
    public function getRecipeCostAttribute(): float
    {
        return round($this->recipeItems->sum(fn ($r) => $r->line_cost), 2);
    }

    /** Food-cost % = recipe cost ÷ menu price × 100. Null if no recipe or zero price. */
    public function getFoodCostPercentAttribute(): ?float
    {
        $price = (float) $this->price;
        if ($price <= 0 || $this->recipeItems->isEmpty()) {
            return null;
        }

        return round(($this->recipe_cost / $price) * 100, 1);
    }

    /**
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    public static function boot()
    {
        parent::boot();

        /* D4: clean up images only on FORCE delete (purge), not soft delete, so
           the files survive a reversible trash and "undo" restores a whole row.
           storage/menu is a shared library and remove_file() only unlinks a file
           nothing else points at, so this row must already be gone before the
           count is taken. */
        self::forceDeleted(function ($menu) {
            if (! empty($menu->image) && $menu->image != 'default.png') {
                remove_file('storage/menu/', $menu->image, $menu->store_id);
            }

            foreach ((array) ($menu->gallery_images ?: []) as $galleryImage) {
                if (! empty($galleryImage) && $galleryImage != 'default.png') {
                    remove_file('storage/menu/', $galleryImage, $menu->store_id);
                }
            }
        });
    }

    /**
     * HIDDEN CATEGORY: storefront visibility. An item whose category row is
     * MISSING (the barcoder-era orphans) or INACTIVE never reaches a customer
     * menu; a NULL category_id stays visible — category-less items are
     * long-standing intended behaviour (MenuSchedulesTest grey-out path).
     * Lives here, off the publicView assembly, so every theme honours it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleOnStorefront(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q
            ->whereNull('items.category_id')
            ->orWhereHas('category', fn ($c) => $c->where('categories.active', '1')));
    }

    /**
     * rev/multi-vertical Lane F2 — has this item's `expiry_date` passed?
     * Null (not batch/expiry tracked) is never expired. Compared on the DATE
     * only (a "today" expiry is still sellable through end of day).
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast() && ! $this->expiry_date->isToday();
    }

    /**
     * Is this item within `$days` of its expiry_date (inclusive), but not yet
     * expired? Used by the pharmacy dashboard's low-expiry alert.
     */
    public function isExpiringWithin(int $days): bool
    {
        if ($this->expiry_date === null || $this->is_expired) {
            return false;
        }

        return $this->expiry_date->lessThanOrEqualTo(now()->addDays($days)->endOfDay());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString());
    }

    /**
     * P6-300: shared low-stock rule — tracked AND threshold set AND at or below threshold.
     * A NULL `low_stock_threshold` is treated as "not tracked for low-stock"
     * (the owner deliberately left it unset), so those items never match. The
     * inventory page count and the daily digest both read through this scope
     * so the dashboard badge and the e-mail can never disagree.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query
            ->where('track_inventory', 1)
            ->whereNotNull('low_stock_threshold')
            ->whereColumn('stock_qty', '<=', 'low_stock_threshold');
    }

    /**
     * P1-03 (J25-1): UUIDv7 public id (HasUuids) for URLs/APIs; the bigint id stays the primary key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
