<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use App\Models\Cuisine;
use App\Models\Customer;
use App\Models\ImageMenu;
use App\Models\MenuCategory;
use App\Models\MenuReview;
use App\Models\StoreFollow;
use App\Models\StoreReview;
use App\Models\StoreTable;
use App\Models\StoreView;
use App\Services\SandboxStores;
use App\Services\Verticals\VerticalRegistry;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\BelongsToTenant;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Rules\PhishingSlug;
use Juwri\Core\Rules\ReservedSlug;
use Juwri\Core\Services\Tenancy\AccountProvisioner;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Core\Support\Hooks;

/**
 * @method \Illuminate\Database\Eloquent\Relations\HasMany<\Illuminate\Database\Eloquent\Model, $this> hotelStays() Hotel dynamic relation (P1-13)
 * @method \Illuminate\Database\Eloquent\Relations\HasMany<\Illuminate\Database\Eloquent\Model, $this> serviceRequests() Hotel dynamic relation (P1-13 13c)
 *
 * @property float|null $mk_min_order P1-05: major amount (number, as the old float cast)
 * @property int|null $mk_min_order_minor P1-05: minor units of mk_min_order_currency
 * @property string|null $mk_min_order_currency
 * @property string|null $public_id UUIDv7 public id (J25-1)
 */
class Store extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;
    use HasUuids;

    /**
     * P1-05: the marketplace minimum order is `mk_min_order_minor` in `mk_min_order_currency` (the store's currency
     * when it was set; an edited minimum moves to the store's current currency). `$store->mk_min_order` stays a
     * number (or null). Query with `mk_min_order_minor`.
     *
     * @var array<string, string>
     */
    protected array $minorMoney = ['mk_min_order' => 'mk_min_order_minor'];

    protected ?string $minorMoneyCurrencyColumn = 'mk_min_order_currency';

    /** P1-04: the tenancy table is `stores` (was `posts`); the class keeps its name until the P1-08 Core move. */
    protected $table = 'stores';

    use BelongsToTenant, HasFactory, Sluggable;

    /**
     * Model hooks, registered after the traits (so the sluggable observer has
     * already filled `slug` when the reserved-slug guard below runs).
     *
     * The dashboard resolves the owner's store id-list / collection once per
     * request into the container (dashboard_store_ids/dashboard_stores).
     * A store create/edit/delete must invalidate that so a same-request re-read —
     * or a test that creates a store then re-queries — stays correct.
     */
    protected static function booted(): void
    {
        $forget = function (): void {
            app()->forgetInstance('__dash_ids_map');
            app()->forgetInstance('__dash_restaurants_map');
        };

        static::saved($forget);
        static::deleted($forget);

        // P0-3 save-gate: never persist custom code unless a superadmin has
        // explicitly enabled it for this restaurant. Central choke point — covers
        // create, update, and any other save path. See rev/platform-v2 P0-3.
        // P9-4: the raw custom-code render sinks + the owner-editor + controller
        // persist paths are retired (GA/Pixel/font are structured now). This hook
        // is kept as defense-in-depth; the columns stay dormant (no data deleted).
        static::saving(function ($store) {
            if (empty($store->custom_code_enabled)) {
                foreach (self::CUSTOM_CODE_FIELDS as $field) {
                    if (! empty($store->$field)) {
                        $store->$field = null;
                    }
                }
            }
        });

        // fusion-reseller FR-11: keep mk_reseller_id in lock-step with the owner's
        // reseller. Recompute only when the owner changes or on create — a plain
        // reseller_id change on the user cascades from the User model's saved hook,
        // so the steady-state save path stays a no-op (no per-save user lookup).
        static::saving(function ($store) {
            if ($store->isDirty('user_id') || ! $store->exists) {
                $store->mk_reseller_id = $store->user_id
                    ? (int) (User::whereKey($store->user_id)->value('reseller_id') ?? 0)
                    : 0;
            }
        });

        // P1-04 tenancy: a store belongs to its owner's account. Set on create and whenever the
        // owner changes (ownership transfer); the store's branches follow it.
        static::saving(function ($store) {
            if (($store->isDirty('user_id') || ! $store->exists) && $store->user_id) {
                $owner = User::find($store->user_id);
                if ($owner) {
                    $store->account_id = AccountProvisioner::forUser($owner)->id;
                }
            }
        });
        // Edition limits (owner 2026-10-05): a new store, or a store that changes type or business, must fit the
        // edition's store/branch limits — checked here, after the saving hooks above set account_id, so every creation
        // path is covered server side. Edits that change neither never are (over-limit stores keep working).
        static::creating(fn (self $store) => StoreLimitGuard::guardStore($store));
        static::updating(fn (self $store) => StoreLimitGuard::guardStore($store));
        // P1-04 slice 5: on an account change every row of the store follows (branches included).
        static::updated(function ($store) {
            if ($store->wasChanged('account_id')) {
                TenantFill::rekeyStore($store->getConnection(), (int) $store->id, $store->account_id ? (int) $store->account_id : null);
            }
        });

        // P1-00a: sluggable 13 registers its observer in whenBooted() (Laravel 13 forbids
        // model work during boot), i.e. after this method. The two slug guards must see the
        // generated slug, so they are registered after it the same way.
        static::whenBooted(function (): void {
            // rev/seo R1: locale codes and platform paths can never be slugs — the
            // sluggable auto-generation path bypasses controller validation (a post
            // titled "AR" would slug to "ar" and shadow a locale URL prefix).
            static::saving(function ($store) {
                if (ReservedSlug::isReserved($store->slug)) {
                    $base = $store->slug.'-menu';
                    $slug = $base;
                    $i = 1;
                    // BL-043: slugs are unique across every account — check outside the tenant scope / RLS.
                    while (TenantContext::bypass(fn (): bool => self::where('slug', $slug)->where('id', '!=', $store->id ?? 0)->exists())) {
                        $slug = $base.'-'.(++$i);
                    }
                    $store->slug = $slug;
                }
            });

            // A4: a NEW store whose auto-generated slug reads like a sign-in or
            // account page ("Secure Login" → secure-login) gets a neutral slug.
            // Existing stores are never renamed (their QR codes must keep working).
            static::saving(function ($store) {
                // A title with no letters or digits ("..", "!!") slugs to "" —
                // every storefront link and the owner's edit page then 500.
                // Same neutral slug as the phishing case.
                // T1: an all-digit slug would bind as a store id on storefront routes.
                if (! $store->exists && (trim((string) $store->slug) === '' || ctype_digit((string) $store->slug) || PhishingSlug::looksLikePhishing($store->slug))) {
                    do {
                        $slug = 'store-'.strtolower(Str::random(6));
                    } while (TenantContext::bypass(fn (): bool => self::where('slug', $slug)->exists())); // BL-043: across every account
                    $store->slug = $slug;
                }
            });
        });

        // #76: every restaurant gets a default "Main" branch on creation.
        static::created(function ($store) {
            if (Schema::hasTable('branches')
                && ! Branch::where('store_id', $store->id)->exists()) {
                Branch::create([
                    'store_id' => $store->id,
                    'name' => mb_substr(($store->title ? $store->title.' — Main' : 'Main'), 0, 255),
                    'address' => $store->address ?? null,
                    'phone' => $store->phone ? mb_substr((string) $store->phone, 0, 191) : null,
                    'whatsapp' => $store->phone ? mb_substr((string) $store->phone, 0, 191) : null,
                    'active' => true,
                    'position' => 0,
                ]);
            }
        });

        // Deleting a store removes its uploaded images (the shared default.png
        // stays), then every one of its menu categories — top-level AND
        // subcategories, each of which deletes its own items — and its picture
        // menu pages. Querying MenuCategory directly (not menu_categories(),
        // which is top-level only) is what keeps subcategories from being left
        // behind pointing at a deleted store; see
        // rev/audit-residue/DATA-REPAIR-PLAN.md §3.3.
        static::deleting(function (self $store): void {
            foreach (self::UPLOADED_IMAGES as $column => $folder) {
                $file = $store->{$column};
                if (empty($file) || $file === 'default.png') {
                    continue;
                }
                remove_file("storage/store/{$folder}/{$file}");
            }

            MenuCategory::where('store_id', $store->id)->get()->each->delete();
            $store->loadMissing('image_menus')->image_menus->each->delete();
        });
    }

    /**
     * Every column is writable; store forms validate their own field lists.
     *
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * Attribute casts. F2: is_verified backs the superadmin-set "Official store ✓"
     * badge — themes must treat it as a strict boolean.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'is_verified' => 'boolean',
        // Marketplace (P0-2)
        'mk_enabled' => 'boolean',
        'mk_lat' => 'float',
        'mk_lng' => 'float',
        'mk_zone_ids' => 'array',
        'mk_delivery_modes' => 'array',
        // Marketplace curation (T5-3)
        'mk_featured' => 'boolean',
        'mk_reviewed_at' => 'datetime',
        // P1-07: typed store settings (read/write through Juwri\Core\Settings\StoreSettings)
        'settings' => 'array',
    ];

    /**
     * The typed settings tree is read through StoreSettings, never dumped with the row.
     *
     * @var list<string>
     */
    protected $hidden = ['settings'];

    /** The store's own currency (a new store: the platform's). */
    public function defaultMinorMoneyCurrency(): string
    {
        $id = $this->attributes['id'] ?? null;

        return strtoupper($id ? store_currency((int) $id) : (string) (config('settings.currency_code') ?: 'USD'));
    }

    /**
     * rev/multi-vertical F0-1 — the store's vertical capability set, resolved
     * through the registry so a legacy/unknown store_type falls back to
     * 'restaurant'. Prefer this over reading store_type literals.
     *
     * @return array<string,mixed>
     */
    public function verticalConfig(): array
    {
        return app(VerticalRegistry::class)->forStore($this);
    }

    /** Does this store's vertical use the food-only attribute set? */
    public function isFoodVertical(): bool
    {
        return app(VerticalRegistry::class)
            ->isFood($this->store_type ?? null);
    }

    /**
     * Implicit route binding by id or slug: numeric segments (< 19 digits)
     * resolve by primary key; slugs resolve ONLY on public storefront routes
     * so owner/dashboard/admin/API routes stay id-only.
     * PostgreSQL raises 22P02 (500) for `id = 'a-slug'`, so the id branch is
     * taken only for safe positive-integer-length digit strings.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $str = (string) $value;
        if (ctype_digit($str) && strlen($str) < 19) {
            return $this->where($this->getKeyName(), $value)->first();
        }

        if ($this->allowsSlugRouteBinding()) {
            return $this->where('slug', $str)->first();
        }

        return null;
    }

    /** Public storefront routes that accept `{store}` as a slug. */
    private function allowsSlugRouteBinding(): bool
    {
        $route = request()->route();
        if ($route === null) {
            return false;
        }

        $uri = ltrim($route->uri(), '/');
        if (str_starts_with($uri, '{locale}/')) {
            $uri = substr($uri, strlen('{locale}/'));
        }

        // store.applyCoupon binds by numeric id only (SendOrderContractTest).
        if ($uri === '{store}/coupon') {
            return false;
        }

        return str_starts_with($uri, '{store}/')
            || str_starts_with($uri, 'r/{store}/')
            || str_starts_with($uri, 'auth/social/customer/{store}/');
    }

    /**
     * Resolve a store by its slug or (when the key is all digits) its numeric id. PostgreSQL rejects a
     * non-numeric string compared with the bigint id column, so the id branch is added only for digits.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeIdOrSlug(Builder $query, mixed $key): Builder
    {
        $key = (string) $key;

        return $query->where(function (Builder $q) use ($key) {
            // P6-40: slugs are stored lowercase; `/Foo` finds `foo` on PostgreSQL too (MySQL's ci collation did).
            $q->whereIn('slug', array_values(array_unique([$key, mb_strtolower($key)])));
            if (ctype_digit($key) && strlen($key) < 19) {
                $q->orWhere('id', (int) $key);
            }
        });
    }

    /**
     * Scope: only stores of a given vertical.
     *
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    public function scopeOfVertical(Builder $query, string $type): Builder
    {
        return $query->where('store_type', $type);
    }

    /**
     * fusion-reseller FR-12: the ONE marketplace fence. A store is visible on a
     * marketplace when it is opted in (mk_enabled) AND approved (mk_status) AND
     * belongs to that marketplace:
     *   - platform Fusion (tenant null)  → mk_platform_listed = 1 (D1 dual listing)
     *   - reseller Fusion (tenant set)   → mk_reseller_id = tenant id
     *
     * Every new marketplace query goes through here or through mkApproved() (its
     * current-tenant alias). Inline where-chains on mk_ columns bypass the fence.
     *
     * rev/mv-surface-api-mcp-fusion-docs: optional `$storeType` narrows the fence
     * to ONE vertical (posts.store_type, e.g. 'retail'/'pharmacy'/'laundry') so a
     * marketplace surface can ask for just its vertical instead of filtering the
     * whole platform's stores in PHP afterwards. It is a plain `where` — an
     * unknown/garbage value simply matches no rows rather than 500ing, and
     * callers that want the "legacy or unknown store_type" fallback should
     * resolve it through VerticalRegistry first (as the rest of the codebase
     * already does) rather than relying on this scope to do it for them.
     * Omitted (the default, and what every existing call site still passes)
     * keeps the fence exactly as it was: every vertical, unfiltered.
     *
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    public function scopeMkApprovedFor(Builder $query, ?Model $tenant, ?string $storeType = null): Builder
    {
        $query->where('mk_enabled', true)->where('mk_status', 'approved');

        if ($tenant) {
            $query->where('mk_reseller_id', $tenant->getKey());
        } else {
            $query->where('mk_platform_listed', true);
        }

        if ($storeType !== null && $storeType !== '') {
            $query->where('store_type', $storeType);
        }

        // P1-23 (AUP 3.1): stores the law keeps off public listings right now (an unpublished pharmacy — the
        // Pharmacy module fills the slot). before: marketplace search and home rails still listed them.
        Hooks::call(self::PUBLIC_LISTING_HOOK, $query);

        return $query;
    }

    /**
     * P6-48: the same public-listing fence as {@see scopeMkApprovedFor()}, for public surfaces that do not go
     * through the marketplace scope (sitemap, landing-page logo strip): hides what the law keeps off public lists.
     *
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    public function scopePubliclyListed(Builder $query): Builder
    {
        Hooks::call(self::PUBLIC_LISTING_HOOK, $query);

        return $query;
    }

    /** Hook slot: narrows a public store listing query ({@see scopeMkApprovedFor()}); hooks get the Builder. */
    public const PUBLIC_LISTING_HOOK = 'store.public-listing';

    /**
     * Marketplace visibility for the CURRENT request tenant (T5-3; FR-12).
     * Reads the container binding 'mk.tenant' (null on platform hosts) so the ~37
     * existing call sites keep compiling and inherit the per-tenant fence.
     *
     * rev/mv-surface-api-mcp-fusion-docs: optional `$storeType` forwards to
     * scopeMkApprovedFor() above (see its docblock) so callers can write
     * `Post::mkApproved($storeType)` for a single-vertical fence. Every
     * existing `Post::mkApproved()` call (no args) is completely unaffected.
     */
    public function scopeMkApproved($query, ?string $storeType = null)
    {
        $tenant = app()->bound('mk.tenant') ? app('mk.tenant') : null;

        return $query->mkApprovedFor($tenant, $storeType);
    }

    /** @return BelongsTo<City, $this> */
    public function mkCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'mk_city_id');
    }

    /**
     * DIRECTORY P0-2: the store's cuisines (max 3, enforced at the edit form).
     * Powers directory filters, city/cuisine landing pages and `servesCuisine`
     * in the Restaurant JSON-LD.
     */
    public function cuisines()
    {
        return $this->belongsToMany(Cuisine::class, 'cuisine_store', 'store_id', 'cuisine_id');
    }

    /** DIRECTORY P0-2: store-level reviews (distinct from item MenuReviews). */
    public function storeReviews()
    {
        return $this->hasMany(StoreReview::class, 'store_id');
    }

    /**
     * P6-285: store-level rating pulled live from the marketplace review row
     * written by StoreController::submitTrackReview (menu_reviews with
     * order_id NOT NULL). Distinct from `storeReviews()` (the dedicated
     * directory StoreReview table) and from the per-item Menu.rating_avg
     * (which only counts order_id IS NULL rows). 0 / null when there are
     * no approved store-level reviews yet, so a blank storefront page
     * shows nothing rather than a misleading zero.
     *
     * @return array{avg: float, count: int}
     */
    public function storeRating(): array
    {
        $row = MenuReview::where('store_id', $this->id)
            ->whereNotNull('order_id')
            ->where('status', 'approved')
            ->selectRaw('COALESCE(AVG(stars), 0) as avg_stars, COUNT(*) as cnt')
            ->first();

        return [
            'avg' => (float) ($row->avg_stars ?? 0),
            'count' => (int) ($row->cnt ?? 0),
        ];
    }

    /**
     * F5-5: drop developer sandbox stores from a query. Apply this on every
     * surface that treats stores as real business — platform analytics, reseller
     * seat billing, plan quotas, the public catalog, the sitemap. NOT a global
     * scope on purpose: the partner console and the developer's own dashboard
     * must still see their sandbox. {@see SandboxStores}
     */
    public function scopeExcludingSandbox($query)
    {
        return SandboxStores::exclude($query, 'id');
    }

    /** Is this store a developer sandbox (excluded from real-business metrics)? */
    public function isSandbox(): bool
    {
        return SandboxStores::isSandbox($this->id);
    }

    /**
     * The storefront URL slug is generated from the store title (the reserved
     * word guard in booted() runs after it).
     *
     * @return array<string, array<string, string>>
     */
    public function sluggable(): array
    {
        return ['slug' => ['source' => 'title']];
    }

    /**
     * P1-04: the tenant account that owns this store.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * QR-scan / page-view log rows.
     *
     * @return HasMany<StoreView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(StoreView::class, 'store_id');
    }

    public function branches()
    {
        return $this->hasMany(Branch::class, 'store_id')->orderBy('position');
    }

    /**
     * Top-level menu categories with their subcategory tree — and NOTHING else.
     *
     * H12: this used to be `->with(['subcategories', 'menus'])`, and
     * MenuCategory::subcategories() chained `->with('menus')` on top, so a single
     * read of $store->menu_categories fired FOUR queries and loaded the store's
     * entire `menu` table — unfiltered by `active`, outside StorefrontMenuCache,
     * duplicating the branch- and schedule-decorated copy that
     * User/StoreController::publicView already assembles as $total_menus. The 17
     * kept themes render items from $total_menus and read this relation only for
     * the category nav.
     *
     * The 15 LEGACY templates DO render $category->menus. They use
     * menu_categories_with_items() below. Do NOT re-add a `menus` eager load
     * here to make a legacy theme work — point the theme at the other relation.
     *
     * @return HasMany<MenuCategory, $this>
     */
    public function menu_categories(): HasMany
    {
        // HIDDEN CATEGORY: storefront-scoped. INACTIVE categories (and the
        // items filed under them) never render on the customer menu — the
        // audit command lands rescued items in an inactive "Uncategorized"
        // bucket and this keeps it invisible everywhere a theme walks the
        // relation for chips, nav or sections. The merchant's menu editor
        // deliberately queries MenuCategory DIRECTLY (menu/index.blade.php)
        // so owners still see and unhide hidden buckets.
        return $this->activeTopLevelCategories()->with('subcategories');
    }

    /**
     * The store's active top-level categories in menu order — the base both
     * storefront category relations add their eager loads to.
     *
     * @return HasMany<MenuCategory, $this>
     */
    private function activeTopLevelCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class, 'store_id')->where('active', '1')->whereNull('parent')->orderBy('position');
    }

    /**
     * menu_categories plus every category's ACTIVE items — the shape the 15
     * legacy templates render ($category->menus / $subcategory->menus).
     *
     * The `active` filter lives on THESE eager-load closures and never inside
     * MenuCategory::menus(), because the store-clone path
     * (User/StoreController.php) and StoreBackupService must still see inactive
     * items. The legacy blades' own ->where('active','1') collection filter
     * becomes a no-op, which is why they need no other change.
     *
     * A blade must use EITHER this relation OR menu_categories, never both: they
     * are two distinct relations and each is loaded and cached separately, so
     * mixing them in one template doubles the category queries.
     *
     * @return HasMany<MenuCategory, $this>
     */
    public function menu_categories_with_items(): HasMany
    {
        // Legacy-theme storefront path — same hidden-category rule as
        // menu_categories() above: an INACTIVE category renders nothing, not
        // even its items. The editor queries MenuCategory directly.
        $activeOnly = fn ($q) => $q->where('active', '1');

        return $this->activeTopLevelCategories()->with([
            'subcategories' => $activeOnly,
            'subcategories.menus' => $activeOnly,
            'menus' => $activeOnly,
        ]);
    }

    /**
     * Picture-menu pages in display order.
     *
     * @return HasMany<ImageMenu, $this>
     */
    public function image_menus(): HasMany
    {
        return $this->hasMany(ImageMenu::class, 'store_id')->orderBy('position');
    }

    /**
     * Every menu category of this restaurant, flattened parent-then-children:
     * a parent, that parent's subcategories, then the next parent — the order the
     * themes render today.
     *
     * Why this exists: menu_categories() is whereNull('parent'), so it is top-level
     * only, while a menu item keys off its OWN category_id, which may be a
     * subcategory id. A theme that iterates menu_categories() and matches items by
     * $cat->id silently drops every subcategory item — 6,650 items across 341 stores.
     * Iterate this instead.
     *
     * Costs no query of its own. It is built from the menu_categories relation, which
     * already eager-loads subcategories, so the children are read from memory. If that
     * relation is not loaded it resolves through the ordinary relation accessor: one
     * query, memoised on the model — exactly what reading $store->menu_categories costs,
     * never more. A caller rendering many restaurants must eager-load menu_categories
     * here just as it must today.
     *
     * Dangling parents: a subcategory whose parent row no longer exists stays
     * unreachable, exactly as it is today. The list is built from the loaded parents
     * downward, so an orphan is nobody's child and is simply never emitted — it is not
     * resurrected and it does not crash. Repairing that data is a separate, human-gated
     * job; do not "fix" it here.
     *
     * @return Attribute<EloquentCollection<int, MenuCategory>, never>
     */
    protected function menuCategoriesFlat(): Attribute
    {
        return Attribute::make(
            get: function (): EloquentCollection {
                $flat = new EloquentCollection;

                foreach ($this->menu_categories as $category) {
                    $flat->push($category);

                    foreach ($category->subcategories as $subcategory) {
                        $flat->push($subcategory);
                    }
                }

                return $flat;
            },
        )->shouldCache();
    }

    /**
     * menu_categories_flat, minus the categories that hold no active item of
     * their own.
     *
     * In a flat theme every category is its own chip and its own section, so a
     * container parent (no direct items, only subcategories) and an emptied-out
     * category each render a chip that opens an empty grid. Several themes
     * hand-rolled the same "drop the empties" filter; this is the one owner of
     * it, so theme #17 inherits the filter instead of the empty chips.
     *
     * "Has an active item" is read from the already-loaded menus relation — the
     * same rows a theme counts from $total_menus — so it costs no query beyond
     * menu_categories_flat and matches what the storefront actually renders.
     * active is the enum('0','1') string; compare it as '1', never as int 1.
     *
     * A theme that WANTS empty category headers (e.g. to show a coming-soon
     * section) should keep using menu_categories_flat and say so; this is for
     * the ones that do not.
     *
     * @return Attribute<EloquentCollection<int, MenuCategory>, never>
     */
    protected function menuCategoriesFlatFilled(): Attribute
    {
        return Attribute::make(
            get: function (): EloquentCollection {
                $flat = $this->menu_categories_flat;

                /* H12: menu_categories() no longer eager-loads menus (the 17 kept
                   themes never read them), but this accessor does. loadMissing
                   fetches them for the WHOLE flattened set in ONE query instead of
                   one per category, and is a no-op when the caller already
                   eager-loaded them (Post::with('menu_categories.menus') …) or used
                   menu_categories_with_items(). */
                $flat->loadMissing('menus');

                return $flat
                    ->filter(fn ($category) => $category->menus
                        ->contains(fn ($menu) => (string) $menu->active === '1'))
                    ->values();
            },
        )->shouldCache();
    }

    /**
     * Dine-in tables (table QR codes).
     *
     * @return HasMany<StoreTable, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(StoreTable::class, 'store_id', 'id');
    }

    /**
     * F2 · Follow / subscribe-to-brand. The customers following this restaurant.
     */
    public function follows()
    {
        return $this->hasMany(StoreFollow::class, 'store_id');
    }

    /**
     * F2 · Live follower count. Uses the follows relation count so an eager-loaded
     * `withCount('follows')` (exposed as follows_count) is reused when present,
     * otherwise falls back to a single COUNT query. Never touches raw SQL, so it is
     * safe on prefixed databases.
     */
    public function getFollowerCountAttribute(): int
    {
        if (array_key_exists('follows_count', $this->attributes)) {
            return (int) $this->attributes['follows_count'];
        }

        return (int) $this->follows()->count();
    }

    /**
     * F2 · Whether a given customer follows this restaurant. Accepts a Customer
     * model or a raw customer id; null (logged-out) is always false — the theme
     * turns a false into the "Follow" affordance and a true into "Following".
     *
     * @param  Customer|int|null  $customer
     */
    public function isFollowedBy($customer): bool
    {
        $customerId = $customer instanceof Customer ? $customer->getKey() : $customer;

        if (empty($customerId)) {
            return false;
        }

        return $this->follows()->where('customer_id', $customerId)->exists();
    }

    /**
     * F2 · Superadmin-set "Official store ✓" badge. True only when a superadmin has
     * flipped is_verified — the single gate the theme checks before rendering the mark.
     */
    public function isVerified(): bool
    {
        return (bool) $this->is_verified;
    }

    /** Custom-code fields that are only allowed when custom_code_enabled is true (P0-3). */
    public const CUSTOM_CODE_FIELDS = ['head_code', 'footer_code', 'js_code', 'css_code', 'google_tag', 'facebook_pixel'];

    /** Uploaded image columns → their folder under storage/restaurant. */
    private const UPLOADED_IMAGES = [
        'cover_image' => 'cover',
        'main_image' => 'logo',
        'bio_cover_image' => 'cover',
        'bio_main_image' => 'logo',
    ];

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
