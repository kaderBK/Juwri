<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuBanner;
use App\Models\MenuCategory;
use App\Models\MenuReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceSchedule;
use App\Models\StoreOption;
use App\Models\Story;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Loyalty\Models\PriceScheduleTarget;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Storefront\Models\ThemeSetting;

/**
 * WI-G2: one complete, vibe-matched demo store per modern post_template (17).
 * Idempotent — re-running refreshes and never duplicates. Per DECISIONS D8 the
 * stores are marked demo (demo_showcase=1) and showcased by direct link only.
 *
 * Flagships (crave / velora / ember) get a REAL professional store built from
 * curated data files (database/seeders/data/showcase/<theme>.php): two-tier
 * categories with images, 30-100 items with bilingual copy, macros, allergens,
 * option groups, priced add-ons w/ images, galleries, ratings and reviews. The
 * photos are real food photography shipped in database/seeders/assets/showcase/
 * and copied into public/storage/menu on seed. The other 14 keep the compact
 * starter menu until they are upgraded the same way.
 *
 *   php artisan db:seed --class=Juwri\\Modules\\Storefront\\Database\\Seeders\\ThemeShowcaseSeeder
 */
class ThemeShowcaseSeeder extends Seeder
{
    /** Name of the demo price schedule, used to find and rebuild it on reseed. */
    private const HAPPY_HOUR = 'Demo Happy Hour';

    /**
     * Themes with a full pro data file (all 17 as of the 14-theme upgrade).
     * M13: DELIBERATELY a separate literal from KeptTemplates::MODERN — "has a pro
     * data file" is a different concept that merely coincides with the retained 17
     * today. KeptTemplateRegistryTest asserts PRO ⊆ MODERN (public so it can read it).
     */
    public const PRO = [
        'crave', 'matcha', 'glow', 'aurora', 'vivid', 'feast', 'velvet', 'dough',
        'plate', 'lime', 'picnic', 'orbit', 'velora', 'crumb', 'soiree', 'scoop', 'ember',
    ];

    /** template => [name, cuisine, color] (basic tier; pro tier overrides from its data file). */
    private const THEMES = [
        'crave' => ['Crave House',    'American grill',   '#e0480f'],
        'matcha' => ['Matcha Tea Room', 'Japanese tea house', '#3f7d4f'],
        'glow' => ['Glow Coffee',    'Specialty coffee', '#0f7a6b'],
        'aurora' => ['Aurora Bistro',  'Nordic bistro',    '#2b5fa6'],
        'vivid' => ['Vivid Kitchen',  'Street food',      '#d81e5b'],
        'feast' => ['Feast Hall',     'Family feast',     '#b8460f'],
        'velvet' => ['Velvet Lounge',  'Fine dining',      '#5b2a86'],
        'dough' => ['Dough & Co',     'Bakery & pizza',   '#c98a2b'],
        'plate' => ['Plate',          'Modern plated',    '#334155'],
        'lime' => ['Lime Fresh',     'Healthy bowls',    '#5fa832'],
        'picnic' => ['Picnic Park',    'Deli & sandwiches', '#e0a615'],
        'orbit' => ['Orbit Diner',    'Retro diner',      '#1f9bd8'],
        'velora' => ['Velora',         'Mediterranean',    '#a6572b'],
        'crumb' => ['Crumb Bakehouse', 'Artisan bakery',   '#8a5a2b'],
        'soiree' => ['Soirée',         'Wine & tapas',     '#7a1f3d'],
        'scoop' => ['Scoop',          'Ice cream & desserts', '#e06ba6'],
        'ember' => ['Ember Steakhouse', 'Grill & steak',   '#8a2b1f'],
    ];

    /** Non-production demo installs show the platform marketing site. Production seed leaves it off. */
    public static function enablePlatformMarketingForDemo(): void
    {
        if (app()->isProduction()) {
            return;
        }

        PlatformSettings::set('platform_marketing', true);
    }

    public function run(): void
    {
        self::enablePlatformMarketingForDemo();
        $owner = $this->owner();

        foreach (self::THEMES as $template => [$name, $cuisine, $color]) {
            if (in_array($template, self::PRO, true) && is_file($this->dataFile($template))) {
                $this->buildProStore($owner, $template);
            } else {
                $this->buildBasicStore($owner, $template, $name, $cuisine, $color);
            }
        }
    }

    /**
     * The demo owner every showcase store belongs to.
     *
     * Public so the theme parity seeder can build its stores under the SAME
     * account (one owner owns every demo store, so the dashboard lists them
     * together).
     */
    public function owner(): User
    {
        return User::updateOrCreate(
            ['email' => 'showcase-demo@example.com'],
            [
                'username' => 'themeshowcase', 'name' => 'Theme Showcase Owner',
                'password' => Hash::make('showcase-demo-pass'), 'user_type' => 'user',
                'group_id' => 2, 'status' => 1, 'country_code' => 'SA', 'country' => 'Saudi Arabia',
                'email_verified_at' => now(),
            ]
        );
    }

    /* ============================ PRO TIER ============================ */

    private function dataFile(string $template): string
    {
        return database_path("seeders/data/showcase/$template.php");
    }

    /**
     * Build (or rebuild) the pro store of one theme.
     *
     * `$spec` defaults to the theme's own data file and `$slug` to
     * "<template>-demo". The theme parity seeder passes a reshaped spec and its
     * own slug, so it can build a synthetic store of a different shape from the
     * same data.
     */
    /**
     * `$features` (theme parity seeder) switches parts of the showcase off per store:
     * banners, stories (the circle rail) and offers (the happy-hour strip) default to on;
     * `splash` (null = leave the theme default) writes the theme's `splash.enabled` value.
     *
     * @param  array{banners?: bool, stories?: bool, offers?: bool, splash?: bool|null}  $features
     */
    public function buildProStore(User $owner, string $template, ?array $spec = null, ?string $slug = null, array $features = []): void
    {
        $features += ['banners' => true, 'stories' => true, 'offers' => true, 'splash' => null];
        $spec ??= include $this->dataFile($template);
        $storeSpec = $spec['store'];
        $slug ??= $template.'-demo';

        $store = Store::updateOrCreate(
            ['slug' => $slug],
            [
                'user_id' => $owner->id, 'title' => $storeSpec['title'], 'sub_title' => $storeSpec['subtitle'],
                'description' => $storeSpec['description'], 'color' => $storeSpec['color'],
                'address' => 'King Fahd Rd, Riyadh', 'phone' => '966500000000',
                'catalog_visible' => 1, 'is_verified' => true,
                // Logo and cover live in their OWN public dirs, not menu/: the
                // dashboard store list and the storefront layout read them from
                // storage/store/logo|cover (main_image / cover_image are bare
                // filenames those paths prepend to). Copying them into menu/ like
                // an item photo left every demo store's logo and cover 404ing.
                'main_image' => $this->photoInto($storeSpec['logo'], 'store/logo'),
                'cover_image' => $this->photoInto($storeSpec['cover'], 'store/cover'),
            ]
        );
        $rid = $store->id;

        foreach ([
            'store_template' => $template,
            'demo_showcase' => '1',
            'restaurant_on_table_order' => '1',
            'restaurant_takeaway_order' => '1',
            'restaurant_delivery_order' => '1',
            'customer_auth_mode' => 'optional',
            // Bilingual storefront: every pro item ships full Arabic translations,
            // so Arabic is the default and the switcher offers English.
            'menu_languages' => json_encode(['ar', 'en']),
            'default_language' => 'ar',
        ] as $k => $v) {
            StoreOption::updateStoreOption($rid, $k, $v);
        }

        Branch::updateOrCreate(
            ['store_id' => $rid, 'name' => 'Main Branch'],
            ['address' => $storeSpec['title'].', Riyadh', 'phone' => '966500000010', 'latitude' => 24.7136,
                'longitude' => 46.6753, 'timezone' => 'Asia/Riyadh', 'active' => true, 'accepting_orders' => true, 'position' => 0]
        );

        // Full catalog rebuild: demo store, so wipe this restaurant's catalog rows
        // (orders included — they reference item ids) and reseed deterministically.
        OrderItem::whereIn('order_id', Order::where('store_id', $rid)->select('id'))->delete();
        Order::where('store_id', $rid)->delete();
        MenuReview::where('store_id', $rid)->delete();
        DB::table('item_modifier_group')->whereIn('menu_id', Menu::where('store_id', $rid)->select('id'))->delete(); // P1-06
        Menu::where('store_id', $rid)->delete();
        MenuCategory::where('store_id', $rid)->delete();
        MenuVariantOption::where('store_id', $rid)->delete();
        MenuExtra::where('store_id', $rid)->delete();
        MenuBanner::where('store_id', $rid)->delete();

        // Library option groups (additive; themes surface them via the pivot as globalGroups).
        $groupIds = [];
        foreach ($spec['groups'] as $key => $g) {
            $en = array_column($g['opts'], 0);
            $ar = array_column($g['opts'], 1);
            $prices = array_map('floatval', array_column($g['opts'], 2));
            $row = MenuVariantOption::create([
                'store_id' => $rid, 'is_global' => true, 'menu_id' => null,
                'title' => $g['t']['en'], 'options' => $en, 'option_prices' => $prices,
                'required' => (bool) ($g['required'] ?? false),
                'min_select' => $g['min'] ?? (($g['required'] ?? false) ? 1 : 0),
                'max_select' => $g['max'] ?? 1,
                'active' => '1', 'position' => count($groupIds),
                'translations' => ['ar' => ['title' => $g['t']['ar'], 'options' => $ar]],
            ]);
            $groupIds[$key] = $row->id;
        }

        // Library extras (priced add-ons with a real image).
        $extraIds = [];
        foreach ($spec['extras'] as $key => $e) {
            $row = MenuExtra::create([
                'store_id' => $rid, 'is_global' => true, 'menu_id' => null,
                'title' => $e['t']['en'], 'price' => $e['p'], 'image' => $this->photo($e['img']),
                'active' => '1', 'position' => count($extraIds),
                'translations' => ['ar' => ['title' => $e['t']['ar']]],
            ]);
            $extraIds[$key] = $row->id;
        }

        // Two-tier categories + items.
        $itemPool = []; // popular items for reviews/orders
        $pos = 0;
        foreach ($spec['categories'] as $cat) {
            $parent = $this->makeCategory($rid, $cat, null, $pos++);
            foreach ($cat['items'] ?? [] as $i => $item) {
                $itemPool[] = $this->makeItem($rid, $parent->id, $item, $i, $groupIds, $extraIds);
            }
            foreach ($cat['children'] ?? [] as $child) {
                $sub = $this->makeCategory($rid, $child, $parent->id, $pos++);
                foreach ($child['items'] ?? [] as $i => $item) {
                    $itemPool[] = $this->makeItem($rid, $sub->id, $item, $i, $groupIds, $extraIds);
                }
            }
        }

        // Featured items for CTA deep-links: story and banner CTAs open a real
        // dish via /{slug}?item={menu_id} (_partials/item-deeplink), so they land
        // on a product instead of bouncing to the home page. Popular first.
        $featured = array_values(array_filter($itemPool, fn ($m) => $m->is_popular)) ?: array_values($itemPool);
        $itemLink = fn (int $i) => $featured
            ? url('/'.$slug.'?item='.$featured[$i % count($featured)]->id)
            : url('/'.$slug);

        // Banners — each CTA deep-links to a featured dish.
        foreach ($features['banners'] ? $storeSpec['banners'] : [] as $i => $imgKey) {
            MenuBanner::create([
                'store_id' => $rid, 'image' => $this->photo($imgKey),
                'title' => $storeSpec['title'], 'subtitle' => $storeSpec['subtitle'],
                'link_url' => $itemLink($i),
                'position' => $i, 'active' => 1,
            ]);
        }

        // Coupons.
        foreach ($storeSpec['coupons'] as $c) {
            Coupon::updateOrCreate(['user_id' => $owner->id, 'code' => $c['code']], ['percentage' => $c['pct'], 'branch_id' => null]);
        }

        // Reviews on the most popular items (bilingual bodies — a real market mix).
        $popular = array_values(array_filter($itemPool, fn ($m) => $m->is_popular)) ?: $itemPool;
        foreach ($spec['reviews'] as $i => $r) {
            $target = $popular[$i % count($popular)];
            MenuReview::create([
                'store_id' => $rid, 'menu_id' => $target->id,
                'reviewer_name' => $r['name'], 'stars' => $r['stars'],
                'body' => $r['body'][$i % 2 === 0 ? 'en' : 'ar'], 'status' => 'approved',
            ]);
        }

        // Default is bilingual: every showcase store serves both /en and Arabic.
        if ($features['offers']) {
            $this->seedHappyHour($rid, $popular, $itemPool, $storeSpec['happy_hour_label'] ?? ['en' => 'Happy Hour', 'ar' => 'ساعة السعادة'], $storeSpec['happy_hour_extra'] ?? []);
        } else {
            $stale = PriceSchedule::where('store_id', $rid)->where('name', self::HAPPY_HOUR)->pluck('id');
            PriceScheduleTarget::whereIn('schedule_id', $stale)->delete();
            PriceSchedule::whereIn('id', $stale)->delete();
        }
        if ($features['splash'] !== null) {
            ThemeSetting::updateOrCreate(
                ['store_id' => $rid, 'template' => $template],
                ['settings' => ['splash.enabled' => $features['splash']]]
            );
        }

        // A few delivered orders so order-history surfaces have data.
        $customer = Customer::updateOrCreate(
            ['store_id' => $rid, 'email' => "fan@$template.example.com"],
            ['name' => 'Demo Diner', 'phone' => '966500000099']
        );
        foreach (array_slice($popular, 0, 3) as $d => $menu) {
            $order = Order::create([
                'store_id' => $rid, 'customer_id' => $customer->id, 'type' => 'delivery',
                'status' => 'delivered', 'created_at' => now()->subDays($d + 1),
            ]);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1 + $d % 2]);
        }

        // Stories with real media (same storage/menu convention as banners) so the
        // story rail + viewer actually show something, each with an order CTA —
        // the viewer only renders its action button when cta_url is set.
        Story::where('store_id', $rid)->delete();
        // A fuller rail (rich showcase): at least six stories, cycling the store's
        // banner media, each CTA deep-linking to a different featured dish so the
        // "Order now" button lands on a product instead of the home page.
        $storyCount = $features['stories'] ? max(count($storeSpec['banners']), 6) : 0;
        $captions = array_values(array_filter([$storeSpec['title'], $storeSpec['subtitle']]));
        for ($i = 0; $i < $storyCount; $i++) {
            $imgKey = $storeSpec['banners'][$i % max(count($storeSpec['banners']), 1)] ?? ($storeSpec['banners'][0] ?? null);
            if (! $imgKey) {
                break;
            }
            Story::create([
                'store_id' => $rid,
                'caption' => $captions ? $captions[$i % count($captions)] : $storeSpec['title'],
                // cta_label left NULL: the public endpoint localizes ___('Order now')
                // to the shopper's active language.
                'cta_label' => null, 'cta_url' => $itemLink($i),
                'active' => true, 'media_url' => $this->photo($imgKey), 'sort' => $i,
                // Demo fixtures are long-lived release-test infrastructure. A
                // one-week expiry made every story rail silently disappear a
                // few days after seeding and let browser coverage skip stories.
                'starts_at' => now()->subDay(), 'expires_at' => now()->addYears(5),
            ]);
        }
    }

    /**
     * A live 20%-off rule over five popular items, so every demo storefront has
     * something for the offers strip to show.
     *
     * The strip reads a RUNTIME discount — MenuScheduleService::decorate() sets
     * `original_price` only while a schedule is live — so there is no sale-price
     * column to seed instead. The window is deliberately every day, all day: a
     * realistic 16:00–19:00 happy hour would leave the strip empty for 21 hours
     * out of 24 and make a working build look broken.
     *
     * The pro path wipes and rebuilds the catalog on every run, so last run's
     * targets point at deleted item ids. Rebuild the rule rather than updating
     * it, and match on the demo name so an owner's own schedules are untouched.
     */
    /** @param string|array $label plain label, or ['en' => …, 'ar' => …] per-language */
    /**
     * @param  array<int, string>  $pinNames  extra item en-names that always join the sale,
     *                                        on top of the auto picks (per-store happy_hour_extra).
     */
    private function seedHappyHour(int $rid, array $popular, array $itemPool, string|array $label = 'Happy Hour', array $pinNames = []): void
    {
        $translations = null;
        if (is_array($label)) {
            $translations = $label;
            $label = $label['en'] ?? reset($label) ?: 'Happy Hour';
        }
        $stale = PriceSchedule::where('store_id', $rid)->where('name', self::HAPPY_HOUR)->pluck('id');
        PriceScheduleTarget::whereIn('schedule_id', $stale)->delete();
        PriceSchedule::whereIn('id', $stale)->delete();

        // Popular items first, then the rest of the menu — aurora flags only
        // three as popular, and a strip that shows five items on sixteen stores
        // and three on the seventeenth reads as a bug in the strip.
        $items = [];
        foreach ([...$popular, ...$itemPool] as $menu) {
            $items[$menu->id] = $menu;
        }
        $items = array_slice(array_values($items), 0, 5);

        // Per-store explicit pins ride ALONGSIDE the auto picks — crumb keeps Baked
        // Cheesecake on sale regardless of the popular-first ordering that fills the
        // first five. Matched by en name against the pool; unknown names are ignored.
        if ($pinNames) {
            $byName = [];
            foreach ($itemPool as $m) {
                $byName[$m->name] = $m;
            }
            $keyed = [];
            foreach ($items as $m) {
                $keyed[$m->id] = $m;
            }
            foreach ($pinNames as $nm) {
                if (isset($byName[$nm])) {
                    $keyed[$byName[$nm]->id] = $byName[$nm];
                }
            }
            $items = array_values($keyed);
        }

        if (! $items) {
            return;
        }

        $schedule = PriceSchedule::create([
            'store_id' => $rid,
            'name' => self::HAPPY_HOUR,
            'label' => $label,
            'label_translations' => $translations,
            'scope' => 'item',
            // 'percent', not 'percentage' — see PriceSchedule::TYPES.
            'adjustment_type' => 'percent',
            'adjustment_value' => 20,
            // 'store' scope would cover the whole menu; the strip is meant to
            // show a handful of items, so scope to explicit item targets.
            'days' => 'mon,tue,wed,thu,fri,sat,sun', // CSV — an array stores the literal "Array"
            'starts_at' => '00:00:00',
            'ends_at' => '23:59:00',
            'order_types' => null, // every order type
            'priority' => 10,
            'active' => true,
        ]);

        foreach ($items as $menu) {
            PriceScheduleTarget::create([
                'schedule_id' => $schedule->id, 'target_type' => 'item', 'target_id' => $menu->id,
            ]);
        }
    }

    private function makeCategory(int $rid, array $cat, ?int $parentId, int $pos): MenuCategory
    {
        return MenuCategory::create([
            'store_id' => $rid, 'name' => $cat['n']['en'], 'parent' => $parentId,
            'image' => isset($cat['img']) ? $this->photo($cat['img']) : null,
            'position' => $pos, 'active' => '1',
            'translations' => ['ar' => ['title' => $cat['n']['ar']]],
        ]);
    }

    private function makeItem(int $rid, int $catId, array $it, int $pos, array $groupIds, array $extraIds): Menu
    {
        $menu = Menu::create([
            'store_id' => $rid, 'category_id' => $catId,
            'name' => $it['n']['en'], 'description' => $it['d']['en'],
            'price' => $it['p'], 'image' => $this->photo($it['img']),
            'gallery_images' => array_map(fn ($g) => $this->photo($g), $it['gal'] ?? []),
            'calories' => $it['kcal'] ?? null, 'protein' => $it['pro'] ?? null,
            'carbs' => $it['carb'] ?? null, 'fat' => $it['fat'] ?? null,
            'spice_level' => $it['spice'] ?? 0,
            'type' => ! empty($it['veg']) ? 'veg' : 'nonveg',
            'is_vegan' => ! empty($it['vegan']), 'is_halal' => true,
            'is_gluten_free' => ! empty($it['gf']),
            'is_popular' => ! empty($it['pop']), 'is_daily_special' => ! empty($it['spec']),
            'allergies' => implode(',', $it['alg'] ?? []),
            'rating_avg' => $it['rate'] ?? null, 'rating_count' => $it['rc'] ?? 0,
            'track_inventory' => isset($it['stock']), 'stock_qty' => $it['stock'] ?? null,
            'is_sold_out' => ! empty($it['soldout']),
            'active' => '1', 'position' => $pos,
            'translations' => ['ar' => ['title' => $it['n']['ar'], 'description' => $it['d']['ar']]],
        ]);

        foreach ($it['grp'] ?? [] as $i => $key) {
            if (isset($groupIds[$key])) {
                $menu->attachedVariantOptions()->attach($groupIds[$key], [
                    'position' => $i, 'required' => null, 'min_select' => null, 'max_select' => null, 'active' => true,
                ]);
            }
        }
        foreach ($it['ext'] ?? [] as $i => $key) {
            if (isset($extraIds[$key])) {
                $menu->attachedExtras()->attach($extraIds[$key], [
                    'position' => $i, 'price_override_minor' => null, 'active' => true,
                ]);
            }
        }

        return $menu;
    }

    /**
     * Ensure a pool photo is available under public/storage/menu and return its
     * public filename. Photos ship in database/seeders/assets/showcase/.
     */
    private function photo(string $key): string
    {
        return $this->photoInto($key, 'menu');
    }

    /**
     * Copy a pool photo into public/storage/<dir> and return its bare filename.
     * `$dir` is the storage subdirectory the consuming view prepends — 'menu'
     * for item/banner/story images, 'store/logo' and 'store/cover'
     * for the store's own logo and cover, which are read from those paths.
     */
    private function photoInto(string $key, string $dir): string
    {
        $filename = "showcase2-$key.jpg";
        $src = database_path("seeders/assets/showcase/$key.jpg");
        $destDir = public_path("storage/$dir");
        if (is_file($src)) {
            @mkdir($destDir, 0755, true);
            if (! is_file("$destDir/$filename")) {
                @copy($src, "$destDir/$filename");
            }
        }

        return $filename;
    }

    /* =========================== BASIC TIER =========================== */

    private function buildBasicStore(User $owner, string $template, string $name, string $cuisine, string $color): void
    {
        $slug = $template.'-demo';

        $logo = $this->placeholder('store/logo', "showcase-$template-logo.png", $color, $name);
        $itemImg = $this->placeholder('menu', "showcase-$template-item.png", $color, $name);

        $store = Store::updateOrCreate(
            ['slug' => $slug],
            [
                'user_id' => $owner->id, 'title' => $name, 'sub_title' => $cuisine,
                'description' => "$name — a $cuisine showcase store for the $template theme.",
                'color' => $color, 'address' => 'King Fahd Rd, Riyadh', 'phone' => '966500000000',
                'catalog_visible' => 1, 'is_verified' => true,
                'main_image' => $logo, 'cover_image' => $logo,
            ]
        );
        $rid = $store->id;

        foreach ([
            'store_template' => $template,
            'demo_showcase' => '1',
            'restaurant_on_table_order' => '1',
            'restaurant_takeaway_order' => '1',
            'restaurant_delivery_order' => '1',
            'customer_auth_mode' => 'optional',
            // Starter menus are English-only content; default English, Arabic offered.
            'menu_languages' => json_encode(['en', 'ar']),
            'default_language' => 'en',
        ] as $k => $v) {
            StoreOption::updateStoreOption($rid, $k, $v);
        }

        Branch::updateOrCreate(
            ['store_id' => $rid, 'name' => 'Main Branch'],
            ['address' => "$name, Riyadh", 'phone' => '966500000010', 'latitude' => 24.7136, 'longitude' => 46.6753,
                'timezone' => 'Asia/Riyadh', 'active' => true, 'accepting_orders' => true, 'position' => 0]
        );

        $spec = [
            'Signatures' => [['House Special', 45], ['Chef\'s Plate', 52], ['The Classic', 38]],
            'Sides' => [['Fries & Dip', 16], ['Garden Salad', 18], ['Soup of the Day', 20]],
            'Drinks' => [['House Lemonade', 14], ['Cold Brew', 16], ['Sparkling Water', 10]],
        ];
        $firstItem = null;
        $pos = 0;
        foreach ($spec as $catName => $items) {
            $cat = MenuCategory::updateOrCreate(
                ['store_id' => $rid, 'name' => $catName],
                ['position' => $pos, 'active' => '1']
            );
            foreach ($items as $i => $it) {
                $menu = Menu::updateOrCreate(
                    ['store_id' => $rid, 'name' => $it[0]],
                    ['category_id' => $cat->id, 'description' => $it[0].' — signature dish.', 'price' => $it[1],
                        'is_halal' => true, 'is_popular' => $i === 0, 'active' => '1', 'position' => $i,
                        'image' => $itemImg]
                );
                $firstItem ??= $menu;
                if ($i === 0) {
                    MenuVariantOption::updateOrCreate(
                        ['store_id' => $rid, 'menu_id' => $menu->id, 'title' => 'Size'],
                        ['is_global' => false, 'options' => ['Small', 'Regular', 'Large']]
                    );
                    MenuExtra::updateOrCreate(
                        ['store_id' => $rid, 'menu_id' => $menu->id, 'title' => 'Extra portion'],
                        ['price' => 6, 'position' => 0]
                    );
                }
            }
            $pos++;
        }

        Coupon::updateOrCreate(['user_id' => $owner->id, 'code' => strtoupper($template).'10'], ['percentage' => 10, 'branch_id' => null]);

        $customer = Customer::updateOrCreate(
            ['store_id' => $rid, 'email' => "fan@$template.example.com"],
            ['name' => 'Demo Diner', 'phone' => '966500000099']
        );
        $order = Order::updateOrCreate(
            ['store_id' => $rid, 'customer_id' => $customer->id, 'type' => 'delivery'],
            ['status' => 'delivered', 'created_at' => now()->subDays(2)]
        );
        if ($firstItem) {
            OrderItem::updateOrCreate(['order_id' => $order->id, 'item_id' => $firstItem->id], ['quantity' => 2]);
            MenuReview::updateOrCreate(
                ['store_id' => $rid, 'menu_id' => $firstItem->id, 'reviewer_name' => 'Demo Diner'],
                ['stars' => 5, 'body' => 'Loved it — the '.strtolower($cuisine).' vibe is perfect.', 'status' => 'approved']
            );
        }
        Story::updateOrCreate(
            ['store_id' => $rid, 'caption' => 'Now open'],
            ['active' => true, 'media_url' => $itemImg, 'cta_label' => null, 'cta_url' => url('/'.$slug),
                'sort' => 0, 'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(6)]
        );
    }

    /**
     * Write a real brand-colored placeholder PNG under storage/app/public/<dir>/
     * (idempotent — skips if it already exists) and return the filename. Falls
     * back to '' if GD is unavailable, so seeding never fails on a bare host.
     */
    private function placeholder(string $dir, string $filename, string $hex, string $label): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return '';
        }
        // The app serves + stores media under public/storage/<dir>/ (StorageService
        // writes to public_path($path)), NOT the Laravel storage symlink.
        $abs = public_path('storage/'.$dir);
        @mkdir($abs, 0755, true);
        $path = $abs.'/'.$filename;
        if (is_file($path)) {
            return $filename;
        }

        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x') ?: [40, 40, 40];
        $w = 600;
        $h = 400;
        $img = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($img, $r, $g, $b);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        $band = imagecolorallocatealpha($img, 0, 0, 0, 90);
        imagefilledrectangle($img, 0, $h - 90, $w, $h, $band);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagestring($img, 5, 24, $h - 60, substr($label, 0, 40), $white);
        imagepng($img, $path);
        imagedestroy($img);

        return $filename;
    }
}
