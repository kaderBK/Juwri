<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\Coupon;
use App\Models\LoyaltyProgram;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoCredentials;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoOwnerPlan;

/**
 * J62-3: the catalogue layer of the owner hands-on demo (`php artisan juwri:demo-seed`).
 *
 * Runs AFTER the existing demo seeders (ThemePackDemoSeeder, KitDemoSeeder, HotelDemoSeeder,
 * VerticalShowcaseSeeder, PharmacyDemoSeeder, PosV2DemoSeeder) and DemoAccountsSeeder, and
 * finishes what they cannot: ONE owner for every demo store, a multi-branch Arabic-first
 * restaurant, the QR tables, the coupons and the loyalty programs the owner clicks through.
 *
 * Idempotent: every row is keyed on a natural key (slug / name / code / table_no), so a
 * second run updates instead of duplicating. A store that does not exist is skipped with a
 * warning, never fatal.
 *
 * Synthetic data only (J19).
 */
class DemoCatalogSeeder extends Seeder
{
    /** A retained modern theme no other demo store uses (themepack=crave, cafe=matcha, retail=zevoa…). */
    private const BRANCHES_TEMPLATE = 'ember';

    /** Tables per demo restaurant that carries QR ordering. */
    private const TABLES_PER_STORE = 6;

    public function run(): void
    {
        $owner = User::where('email', DemoOwnerPlan::OWNER_EMAIL)->first();
        if (! $owner) {
            $this->command?->warn(
                'DemoCatalogSeeder: owner '.DemoOwnerPlan::OWNER_EMAIL.' is missing — the demo catalogue was skipped.'
            );

            return;
        }

        // The demo owner ends up holding every demo store, so the Community product limit
        // (one store per type per business) would refuse the re-home. Demo fixture data is
        // install-time data, not owner growth — the bypass the guard documents for exactly
        // this case. (The `db:seed` command lifts the limits too, but `juwri:demo-seed`
        // calls the seeders directly, so the seeder lifts them for itself.)
        StoreLimitGuard::bypass(function () use ($owner): void {
            $this->seedCatalog($owner);
        });
    }

    /**
     * The catalogue work itself.
     */
    private function seedCatalog(User $owner): void
    {
        $stores = $this->rehomeDemoStores($owner);

        $branches = $this->seedBranchesStore($owner);
        if ($branches) {
            $stores[$this->branchesSlug()] = $branches;
        }

        $this->ensureArabicAcrossDemoStores($stores);
        $this->seedTables($stores);
        $this->recordTableQrLinks($stores);
        $this->recordHotelLinks($stores);
        $this->seedCoupons($owner);
        $this->seedLoyalty($stores);
    }

    /**
     * The demo credentials collector (job 1 binds it in the container); null when it is not
     * bound, so this seeder still runs on its own.
     */
    private function credentials(): ?DemoCredentials
    {
        if (! app()->bound(DemoCredentials::class)) {
            return null;
        }

        $credentials = app(DemoCredentials::class);

        return $credentials instanceof DemoCredentials ? $credentials : null;
    }

    /**
     * A slug from the demo plan.
     */
    private function planSlug(string $key): string
    {
        return (string) (DemoOwnerPlan::STORES[$key] ?? '');
    }

    /**
     * The multi-branch demo restaurant (the plan owns the slug).
     */
    private function branchesSlug(): string
    {
        return $this->planSlug('branches');
    }

    /**
     * Move every demo store onto the demo owner and tag it, so the `--fresh` wipe knows
     * exactly which rows are demo rows.
     *
     * Keying check (the task's "is anything else keyed by the owner?"): branches, menus,
     * menu categories, customers, loyalty programs, loyalty accounts and store tables all
     * carry `store_id`, so they follow their store. `coupons` are the one owner-keyed table
     * (`coupons.user_id` is the ISSUER, there is no store column) — they are re-homed
     * separately in {@see rehomeOwnerKeyedRows()}.
     *
     * @return array<string, Store> slug => store
     */
    private function rehomeDemoStores(User $owner): array
    {
        $stores = [];
        $previousOwners = [];

        foreach (DemoOwnerPlan::STORES as $slug) {
            $store = Store::where('slug', $slug)->first();
            if (! $store) {
                $this->command?->warn("DemoCatalogSeeder: store {$slug} is missing — skipped.");

                continue;
            }

            if ((int) $store->user_id !== (int) $owner->id) {
                if ($store->user_id && $this->ownsOnlyDemoStores((int) $store->user_id)) {
                    // Checked BEFORE the move: afterwards the owner holds no demo store left.
                    $previousOwners[(int) $store->user_id] = true;
                }
                // The Store model's saving hook re-keys the account (and, through it, the
                // store's own rows) onto the new owner.
                $store->forceFill(['user_id' => $owner->id])->save();
            }

            $this->tagStore($store->fresh());
            $stores[$slug] = $store->fresh();
        }

        $this->rehomeOwnerKeyedRows(array_keys($previousOwners), $owner);

        return $stores;
    }

    /**
     * Mark a store as a demo store (the `--fresh` wipe only touches tagged rows).
     */
    private function tagStore(Store $store): void
    {
        StoreOption::updateStoreOption((int) $store->id, DemoOwnerPlan::TAG_OPTION, DemoOwnerPlan::TAG_VALUE);
    }

    /**
     * `coupons` are keyed by their ISSUER (`coupons.user_id`), not by store, so a store that
     * moves to the demo owner leaves its coupons with the old owner. Move the coupons of the
     * demo-only owners onto the demo owner as well; reseller-issued coupons and coupons the
     * demo owner already holds are left alone.
     *
     * A previous owner only counts as demo-only when EVERY store it holds is a demo store
     * (a plan slug or a `-demo` slug) — checked while the store still sits with that owner —
     * so a real owner never loses its coupons.
     *
     * @param  list<int>  $demoOnlyOwnerIds
     */
    private function rehomeOwnerKeyedRows(array $demoOnlyOwnerIds, User $owner): void
    {
        if ($demoOnlyOwnerIds === []) {
            return;
        }

        foreach (Coupon::whereIn('user_id', $demoOnlyOwnerIds)->get() as $coupon) {
            if ((int) $coupon->user_id === (int) $owner->id || $coupon->reseller_id !== null) {
                continue;
            }
            $coupon->forceFill(['user_id' => $owner->id])->save();
        }
    }

    /**
     * Does this owner hold demo stores only?
     */
    private function ownsOnlyDemoStores(int $ownerId): bool
    {
        $slugs = Store::where('user_id', $ownerId)->pluck('slug')->all();
        if ($slugs === []) {
            return false;
        }

        foreach ($slugs as $slug) {
            if (! $this->isDemoSlug((string) $slug)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A demo store slug: one of the plan's, or any of the `-demo` slugs the demo seeders use.
     */
    private function isDemoSlug(string $slug): bool
    {
        return in_array($slug, DemoOwnerPlan::STORES, true) || str_ends_with($slug, '-demo');
    }

    /* ==================== THE MULTI-BRANCH DEMO RESTAURANT ==================== */

    /**
     * The multi-branch demo restaurant: three branches with their own hours, an Arabic-first
     * menu with variants and extras, and the same order types/coupon box as the rest of the
     * demo. Idempotent: the store keys on its slug, the branches on their names.
     */
    private function seedBranchesStore(User $owner): ?Store
    {
        $slug = $this->branchesSlug();
        if ($slug === '') {
            return null;
        }

        $store = Store::updateOrCreate(
            ['slug' => $slug],
            [
                'user_id' => $owner->id,
                'title' => 'Demo Branches Grill',
                'sub_title' => 'مشوي الفروع التجريبي',
                'description' => 'Multi-branch demo grill — three branches, an Arabic-first menu, QR tables.',
                'is_verified' => true,
                'color' => '#b3541e',
                'address' => 'King Fahd Rd, Riyadh',
                'phone' => '966500000030',
                'catalog_visible' => 1,
                'main_image' => $this->photoInto('grill-spread', 'store/logo'),
                'cover_image' => $this->photoInto('bar-interior', 'store/cover'),
            ]
        );
        $rid = (int) $store->id;

        foreach ([
            'store_template' => $this->branchesTemplate(),
            'restaurant_on_table_order' => '1',
            'restaurant_takeaway_order' => '1',
            'restaurant_delivery_order' => '1',
            'allow_coupons' => '1',
            'customer_auth_mode' => 'optional',
            // English first, Arabic offered — the branch demo is the English-default store.
            'menu_languages' => json_encode(['en', 'ar']),
            'default_language' => 'en',
        ] as $name => $value) {
            StoreOption::updateStoreOption($rid, $name, $value);
        }

        $this->seedBranches($rid, $store->title);
        $this->seedBranchesMenu($rid);

        $this->tagStore($store->fresh());

        return $store->fresh();
    }

    /**
     * The theme slug of the branch demo: a retained modern theme no other demo store uses.
     */
    private function branchesTemplate(): string
    {
        return in_array(self::BRANCHES_TEMPLATE, KeptTemplates::MODERN, true)
            ? self::BRANCHES_TEMPLATE
            : (KeptTemplates::MODERN[0] ?? 'crave');
    }

    /**
     * Three branches with their own opening hours; the Dammam one is closed for orders so
     * the owner can see a branch that does not accept them.
     */
    private function seedBranches(int $rid, string $title): void
    {
        $specs = [
            ['name' => 'Riyadh Olaya', 'city' => 'Riyadh', 'phone' => '966500000031', 'lat' => 24.7136000, 'lng' => 46.6753000, 'hours' => $this->hours('11:00', '23:00'), 'accepting' => true],
            ['name' => 'Jeddah Tahlia', 'city' => 'Jeddah', 'phone' => '966500000032', 'lat' => 21.5853000, 'lng' => 39.1928000, 'hours' => $this->hours('12:00', '01:00'), 'accepting' => true],
            ['name' => 'Dammam Corniche', 'city' => 'Dammam', 'phone' => '966500000033', 'lat' => 26.4207000, 'lng' => 50.0888000, 'hours' => $this->hours('16:00', '02:00'), 'accepting' => false],
        ];

        foreach ($specs as $position => $spec) {
            Branch::updateOrCreate(
                ['store_id' => $rid, 'name' => $spec['name']],
                [
                    'address' => $spec['name'].', '.$spec['city'],
                    'phone' => $spec['phone'],
                    'latitude' => $spec['lat'],
                    'longitude' => $spec['lng'],
                    'timezone' => 'Asia/Riyadh',
                    'active' => true,
                    'accepting_orders' => $spec['accepting'],
                    'position' => $position,
                    'hours' => $spec['hours'],
                ]
            );
        }

        // A new store is born with one automatic "<title> — Main" branch (Store::created).
        // The demo store has exactly three branches, so that automatic one is dropped.
        Branch::where('store_id', $rid)
            ->whereNotIn('name', array_column($specs, 'name'))
            ->whereIn('name', ['Main', $title.' — Main'])
            ->delete();
    }

    /**
     * An opening-hours map keyed by Branch::DAYS.
     *
     * @return array<string, array{open: string, close: string, closed: bool}>
     */
    private function hours(string $open, string $close): array
    {
        $days = [];
        foreach (Branch::DAYS as $day) {
            $days[$day] = ['open' => $open, 'close' => $close, 'closed' => false];
        }

        return $days;
    }

    /**
     * Three categories and thirteen items, every one of them with an Arabic translation.
     * Four items carry variant groups (size / side) and four carry extras.
     */
    private function seedBranchesMenu(int $rid): void
    {
        $catalog = $this->branchesCatalog();

        $categoryPosition = 0;
        foreach ($catalog as $categoryName => $category) {
            $categoryRow = MenuCategory::updateOrCreate(
                ['store_id' => $rid, 'name' => $categoryName],
                [
                    'position' => $categoryPosition,
                    'active' => '1',
                    'translations' => ['ar' => ['title' => $category['ar']]],
                ]
            );

            foreach ($category['items'] as $position => $item) {
                $menu = Menu::updateOrCreate(
                    ['store_id' => $rid, 'name' => $item['en']],
                    [
                        'category_id' => $categoryRow->id,
                        'description' => $item['description'],
                        'price' => $item['price'],
                        'image' => $this->photoInto($item['photo'], 'menu'),
                        'is_halal' => true,
                        'is_popular' => $position === 0,
                        'active' => '1',
                        'position' => $position,
                        'translations' => [
                            'ar' => ['title' => $item['ar'], 'description' => $item['description_ar']],
                        ],
                    ]
                );

                foreach ($item['variants'] ?? [] as $variantPosition => $variant) {
                    MenuVariantOption::updateOrCreate(
                        ['store_id' => $rid, 'menu_id' => $menu->id, 'title' => $variant['title']],
                        [
                            'is_global' => false,
                            'options' => $variant['options'],
                            'option_prices' => $variant['prices'],
                            'required' => $variant['required'],
                            'min_select' => 1,
                            'max_select' => 1,
                            'active' => 1,
                            'position' => $variantPosition + 1,
                        ]
                    );
                }

                foreach ($item['extras'] ?? [] as $extraPosition => $extra) {
                    MenuExtra::updateOrCreate(
                        ['store_id' => $rid, 'menu_id' => $menu->id, 'title' => $extra[0]],
                        ['price' => $extra[1], 'is_global' => false, 'active' => 1, 'position' => $extraPosition]
                    );
                }
            }

            $categoryPosition++;
        }
    }

    /**
     * The branch demo's menu: three categories, thirteen items, real Arabic names, SAR
     * prices. Four items carry variant groups (size / side) and four carry extras.
     *
     * @return array<string, array{ar: string, items: list<array<string, mixed>>}>
     */
    private function branchesCatalog(): array
    {
        $size = [
            'title' => 'Size',
            'options' => ['Small', 'Medium', 'Large'],
            'prices' => [0, 8, 16],
            'required' => true,
        ];

        return [
            'Grills' => [
                'ar' => 'مشاوي',
                'items' => [
                    [
                        'en' => 'Mixed Grill Platter',
                        'ar' => 'مشاوي مشكّلة',
                        'description' => 'Lamb, chicken, kebab and shrimp on one platter.',
                        'description_ar' => 'لحم ودجاج وكباب وروبيان في طبق واحد.',
                        'price' => 89,
                        'photo' => 'grill-spread',
                        'variants' => [
                            $size,
                            ['title' => 'Side', 'options' => ['Rice', 'Fries', 'Green Salad'], 'prices' => [0, 6, 7], 'required' => false],
                        ],
                        'extras' => [['Extra Meat', 22], ['Extra Shrimp', 28]],
                    ],
                    [
                        'en' => 'Chicken Shish Tawook',
                        'ar' => 'شيش طاووق دجاج',
                        'description' => 'Charcoal-grilled chicken skewers with garlic sauce.',
                        'description_ar' => 'أسياخ دجاج مشوية على الفحم مع صلصة الثوم.',
                        'price' => 42,
                        'photo' => 'chicken-shish',
                        'variants' => [$size],
                        'extras' => [['Extra Garlic Sauce', 3]],
                    ],
                    [
                        'en' => 'Lamb Kebab',
                        'ar' => 'كباب لحم',
                        'description' => 'Minced lamb kebab, charcoal grilled.',
                        'description_ar' => 'كباب لحم مفروم مشوي على الفحم.',
                        'price' => 58,
                        'photo' => 'kebab-skewers',
                        'variants' => [$size],
                    ],
                    [
                        'en' => 'Grilled Shrimp',
                        'ar' => 'روبيان مشوي',
                        'description' => 'Jumbo shrimp grilled with lemon and herbs.',
                        'description_ar' => 'روبيان مشوي مع الليمون والأعشاب.',
                        'price' => 75,
                        'photo' => 'shrimp-grilled',
                        'variants' => [$size],
                        'extras' => [['Garlic Butter', 5]],
                    ],
                    [
                        'en' => 'Lamb Chops',
                        'ar' => 'ريش غنم مشوية',
                        'description' => 'French-trimmed lamb chops, rosemary jus.',
                        'description_ar' => 'ريش غنم مشوية مع مرق الروزماري.',
                        'price' => 95,
                        'photo' => 'steak-chop',
                    ],
                ],
            ],
            'Starters' => [
                'ar' => 'مقبلات',
                'items' => [
                    [
                        'en' => 'Tabbouleh Salad',
                        'ar' => 'تبولة',
                        'description' => 'Parsley, tomato, mint and bulgur.',
                        'description_ar' => 'بقدونس وطماطم ونعناع وبرغل.',
                        'price' => 26,
                        'photo' => 'tabbouleh',
                    ],
                    [
                        'en' => 'Fattoush Salad',
                        'ar' => 'فتوش',
                        'description' => 'Garden leaves, sumac and crisp bread.',
                        'description_ar' => 'خضار وسمّاق وخبز مقرمش.',
                        'price' => 24,
                        'photo' => 'fattoush-2',
                    ],
                    [
                        'en' => 'Grilled Vegetables',
                        'ar' => 'خضار مشوية',
                        'description' => 'Seasonal vegetables off the charcoal grill.',
                        'description_ar' => 'خضار موسمية مشوية على الفحم.',
                        'price' => 28,
                        'photo' => 'veg-greens',
                        'extras' => [['Extra Cheese', 6]],
                    ],
                    [
                        'en' => 'Falafel Platter',
                        'ar' => 'فلافل',
                        'description' => 'Five falafel with tahini and pickles.',
                        'description_ar' => 'خمس فلافل مع الطحينة والمخلل.',
                        'price' => 22,
                        'photo' => 'falafel-plate-real',
                    ],
                ],
            ],
            'Drinks' => [
                'ar' => 'مشروبات',
                'items' => [
                    [
                        'en' => 'Saudi Coffee',
                        'ar' => 'قهوة سعودية',
                        'description' => 'Cardamom coffee, served with dates.',
                        'description_ar' => 'قهوة بالهيل مع التمر.',
                        'price' => 12,
                        'photo' => 'coffee-art',
                    ],
                    [
                        'en' => 'Fresh Mint Lemonade',
                        'ar' => 'ليمون بالنعناع',
                        'description' => 'Lemonade blended with garden mint.',
                        'description_ar' => 'ليمون مخفوق مع النعناع.',
                        'price' => 16,
                        'photo' => 'mint-lemonade',
                    ],
                    [
                        'en' => 'Arabic Tea',
                        'ar' => 'شاي عربي',
                        'description' => 'Mint tea brewed with cardamom.',
                        'description_ar' => 'شاي بالنعناع والهيل.',
                        'price' => 10,
                        'photo' => 'tea-pot',
                    ],
                    [
                        'en' => 'Mint Mojito',
                        'ar' => 'موهيتو بالنعناع',
                        'description' => 'Fresh mint, lime and soda.',
                        'description_ar' => 'نعناع طازج وليمون وصودا.',
                        'price' => 20,
                        'photo' => 'mojito-mocktail',
                    ],
                ],
            ],
        ];
    }

    /* ============================== ARABIC ============================== */

    /**
     * Arabic everywhere: an active `ar` language row, `ar` in every demo store's menu
     * languages, and Arabic names on the restaurant store's own menu.
     *
     * A seeder's own `default_language` is never overwritten — only the language LIST is
     * completed, so a store that defaults to Arabic keeps defaulting to Arabic.
     */
    private function ensureArabicAcrossDemoStores(array $stores): void
    {
        // The storefront only offers a menu language whose `languages` row is active.
        Language::updateOrCreate(
            ['code' => 'ar'],
            ['direction' => 'rtl', 'name' => 'Arabic', 'active' => 1]
        );

        foreach ($stores as $store) {
            $rid = (int) $store->id;
            $stored = StoreSettings::legacy($rid, 'menu_languages');
            $languages = $stored !== null ? json_decode($stored, true) : [];
            if (! is_array($languages)) {
                $languages = [];
            }

            if (! in_array('ar', $languages, true)) {
                $languages = $languages === []
                    ? ['en', 'ar']
                    : array_values(array_unique([...$languages, 'ar']));
                StoreSettings::set($rid, 'menu_languages', json_encode($languages));
            }
        }

        $this->addRestaurantArabic($stores);
    }

    /**
     * Arabic names for the restaurant store's categories and items (the ones ThemePackDemoSeeder
     * created in English). Only fills what is missing — a translation that is already there
     * stays untouched.
     */
    private function addRestaurantArabic(array $stores): void
    {
        $restaurant = $stores[$this->planSlug('restaurant')] ?? null;
        if (! $restaurant) {
            return;
        }
        $rid = (int) $restaurant->id;

        foreach ($this->restaurantArabicCategories() as $english => $arabic) {
            foreach (MenuCategory::where('store_id', $rid)->where('name', $english)->get() as $category) {
                $this->mergeArabic($category, ['title' => $arabic]);
            }
        }

        foreach ($this->restaurantArabicItems() as $english => $arabic) {
            foreach (Menu::where('store_id', $rid)->where('name', $english)->get() as $menu) {
                $this->mergeArabic($menu, ['title' => $arabic]);
            }
        }
    }

    /**
     * @return array<string, string> English category name => Arabic title
     */
    private function restaurantArabicCategories(): array
    {
        return [
            'Starters' => 'مقبلات',
            'Mains' => 'الأطباق الرئيسية',
            'Drinks' => 'مشروبات',
        ];
    }

    /**
     * @return array<string, string> English item name => Arabic title
     */
    private function restaurantArabicItems(): array
    {
        return [
            'Hummus Bowl' => 'حمص بالطحينة',
            'Cheese Sambousek' => 'سمبوسك بالجبن',
            'Fattoush Salad' => 'فتوش',
            'Chicken Shawarma' => 'شاورما دجاج',
            'Beef Kabsa' => 'كبسة لحم',
            'Falafel Wrap' => 'فلافل',
            'Grilled Salmon' => 'سلمون مشوي',
            'Fresh Mint Lemonade' => 'ليمون بالنعناع',
            'Saudi Coffee' => 'قهوة سعودية',
        ];
    }

    /**
     * Fill the Arabic translations of one row, leaving the ones that are already there alone.
     *
     * @param  array<string, string>  $fields
     */
    private function mergeArabic(Model $model, array $fields): void
    {
        $translations = (array) ($model->translations ?? []);
        $arabic = (array) ($translations['ar'] ?? []);
        $changed = false;

        foreach ($fields as $field => $value) {
            if (trim((string) ($arabic[$field] ?? '')) === '') {
                $arabic[$field] = $value;
                $changed = true;
            }
        }

        if (! $changed) {
            return;
        }

        $translations['ar'] = $arabic;
        $model->forceFill(['translations' => $translations])->save();
    }

    /* ============================ TABLES + QR ============================ */

    /**
     * Six QR tables on the restaurant store and on the branch demo. firstOrNew on
     * (store_id, table_no) keeps the keys stable, so the QR links the owner is given stay
     * valid between runs.
     */
    private function seedTables(array $stores): void
    {
        foreach ([$this->planSlug('restaurant'), $this->branchesSlug()] as $slug) {
            $store = $stores[$slug] ?? null;
            if (! $store) {
                $this->command?->warn("DemoCatalogSeeder: store {$slug} is missing — its QR tables were skipped.");

                continue;
            }

            $rid = (int) $store->id;
            $branchId = Branch::where('store_id', $rid)->orderBy('id')->value('id');

            for ($no = 1; $no <= self::TABLES_PER_STORE; $no++) {
                $table = StoreTable::firstOrNew(['store_id' => $rid, 'table_no' => $no]);
                $table->forceFill([
                    'store_id' => $rid,
                    'branch_id' => $table->branch_id ?: $branchId,
                    'table_no' => $no,
                    'label' => $table->label ?: 'T'.$no,
                    'seats' => $table->seats ?: 4,
                    'status' => $table->status ?: 'free',
                    // A room stays a room; every other row is a dining table.
                    'area_type' => $table->area_type === 'room' ? 'room' : 'table',
                    // The table's QR token: generated once, then never changed.
                    'key' => $table->key ?: Str::random(10),
                ])->save();
            }
        }
    }

    /**
     * The table QR links of the two demo restaurants (tables 1 and 2), written to the demo
     * credentials file so the owner can scan them from a phone.
     */
    private function recordTableQrLinks(array $stores): void
    {
        $credentials = $this->credentials();
        if (! $credentials) {
            $this->command?->warn('DemoCatalogSeeder: the demo credentials collector is not bound — no QR links were recorded.');

            return;
        }

        foreach ([$this->planSlug('restaurant'), $this->branchesSlug()] as $slug) {
            $store = $stores[$slug] ?? null;
            if (! $store) {
                continue;
            }

            foreach ([1, 2] as $no) {
                $table = StoreTable::where('store_id', $store->id)->where('table_no', $no)->first();
                if (! $table || ! $table->key) {
                    continue;
                }

                $credentials->record(
                    'Table QR '.$slug.' #'.$no,
                    store_public_qr_url($store, $table->key),
                    DemoOwnerPlan::OWNER_EMAIL,
                    '',
                    'link'
                );
            }
        }
    }

    /**
     * The hotel's room QR links (with the signed room token) and the checked-in guest's folio.
     */
    private function recordHotelLinks(array $stores): void
    {
        $credentials = $this->credentials();
        if (! $credentials) {
            return;
        }

        $store = $stores[$this->planSlug('hotel')] ?? null;
        if (! $store) {
            $this->command?->warn('DemoCatalogSeeder: the hotel store is missing — its room links were skipped.');

            return;
        }
        $rid = (int) $store->id;

        $rooms = StoreTable::where('store_id', $rid)->where('area_type', 'room')->orderBy('table_no')->get();
        foreach ($rooms as $room) {
            if (! $room->key) {
                continue;
            }

            $url = store_public_qr_url($store, $room->key);

            // The signed room token (rt=) is what lets a diner charge to the room they
            // physically scanned (J57). The hotel's own signer mints it — never a
            // hand-rolled signature.
            if (! str_contains($url, 'rt=')) {
                $url .= '&rt='.urlencode(app(RoomCharge::class)->mintToken($room));
            }

            $credentials->record(
                'Room QR '.$store->slug.' #'.$room->table_no,
                $url,
                DemoOwnerPlan::OWNER_EMAIL,
                '',
                'link'
            );
        }

        // The checked-in guest's folio: the stay's own bearer token, on the hotel's own route.
        $stay = app(RoomCharge::class)->checkedInStays($rid)->first();
        if ($stay && ! empty($stay->token) && Route::has('hotel.guest.folio')) {
            $credentials->record(
                'Guest folio '.$store->slug,
                route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]),
                DemoOwnerPlan::OWNER_EMAIL,
                '',
                'link'
            );
        }
    }

    /* ============================== COUPONS ============================== */

    /**
     * The two demo coupons of the demo owner: 10% off and 5 SAR off.
     */
    private function seedCoupons(User $owner): void
    {
        $this->seedCoupon($owner, DemoOwnerPlan::COUPON_PERCENT, 'percentage', 10);
        $this->seedCoupon($owner, DemoOwnerPlan::COUPON_FIXED, 'fixed', 5);
    }

    /**
     * One owner-scoped coupon. The code is the row's unique key, so a rerun updates the
     * coupon instead of adding a second one — and the `used` counter is never reset.
     *
     * The schema has no `valid_from` column: a coupon is usable from the moment its row
     * exists (created_at, stamped a day back here), and `expiry_at` is a year out, so
     * neither end of the window can bite the demo.
     */
    private function seedCoupon(User $owner, string $code, string $type, float $value): void
    {
        $coupon = Coupon::firstOrCreate(
            ['user_id' => $owner->id, 'code' => $code],
            [
                'user_id' => $owner->id,
                'percentage' => $value,
                'coupon_type' => $type,
                'branch_id' => null,       // not branch-locked: the storefront pre-flight carries no branch_id
                'applies_to' => 'all',
                'limit' => 0,              // 0 = unlimited
                'used' => 0,
                'expiry_at' => now()->addYear(),
            ]
        );

        if ($coupon->wasRecentlyCreated) {
            $coupon->forceFill(['created_at' => now()->subDay()])->save();
        }

        // Re-assert the discount shape on every run (never touches `used`).
        $coupon->forceFill([
            'user_id' => $owner->id,
            'percentage' => $value,
            'coupon_type' => $type,
            'branch_id' => null,
            'applies_to' => 'all',
            'expiry_at' => now()->addYear(),
        ])->save();
    }

    /* ============================== LOYALTY ============================== */

    /**
     * An active points program on the restaurant store and on the branch demo.
     */
    private function seedLoyalty(array $stores): void
    {
        foreach ([$this->planSlug('restaurant'), $this->branchesSlug()] as $slug) {
            $store = $stores[$slug] ?? null;
            if (! $store) {
                $this->command?->warn("DemoCatalogSeeder: store {$slug} is missing — its loyalty program was skipped.");

                continue;
            }

            LoyaltyProgram::updateOrCreate(
                ['store_id' => $store->id],
                [
                    'type' => 'points',
                    'earn_rate' => 1,
                    'reward_threshold' => 100,
                    'reward_description' => 'SAR 25 off your next order',
                    'reward_discount' => 25,
                    'active' => true,
                    'tiers' => [
                        ['name' => 'Silver', 'min' => 0],
                        ['name' => 'Gold', 'min' => 200],
                        ['name' => 'Platinum', 'min' => 500],
                    ],
                ]
            );
        }
    }

    /* =============================== MEDIA =============================== */

    /**
     * Copy a shipped showcase photo (database/seeders/assets/showcase/$key.jpg) into
     * public/storage/<dir> and return its bare filename — the convention the other demo
     * seeders use. Idempotent (skips an already copied photo) and safe when the asset is
     * absent (the filename is returned either way, so the row stays stable).
     */
    private function photoInto(string $key, string $dir): string
    {
        $filename = 'demo-'.$key.'.jpg';
        $source = database_path('seeders/assets/showcase/'.$key.'.jpg');
        $destination = public_path('storage/'.$dir);

        if (is_file($source)) {
            @mkdir($destination, 0755, true);
            if (! is_file($destination.'/'.$filename)) {
                @copy($source, $destination.'/'.$filename);
            }
        }

        return $filename;
    }
}
