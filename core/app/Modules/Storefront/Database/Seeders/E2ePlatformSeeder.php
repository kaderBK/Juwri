<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;

/**
 * Platform E2E (W0.3) — the single "make the whole demo platform real" seeder the
 * nightly end-to-end run boots against. It rolls up every existing demo-data
 * seeder, then adds the DISPLAY-ONLY theme store those seeders skip
 * (pageview), a shared P1TEST10 coupon, and a demoable baseline
 * (order types, a table, 24/7 hours, Arabic) on all 18 demo stores.
 *
 * IDEMPOTENT: every write keys on a natural key (email / slug / store_id+option /
 * user_id+code / store_id+table_no) via updateOrCreate / firstOrCreate, so
 * running it twice never duplicates a row. The idempotency contract is proven by
 * tests/Modules/Platform/E2ePlatformSeederTest.php (seeds twice, asserts exactly 18 demo
 * stores and exactly one P1TEST10 coupon).
 *
 * ── Schema facts this seeder was written against (discovered, not guessed) ──
 *  · Store template, order types, coupon-toggle and languages are NOT columns on
 *    `posts` — they live in `store_options` (App\Models\StoreOption, keyed by
 *    store_id + option_name). The relevant option names:
 *        store_template          → theme slug (e.g. 'pageview')
 *        restaurant_delivery_order    → '1' enables delivery
 *        restaurant_takeaway_order    → '1' enables pickup / takeaway
 *        restaurant_on_table_order    → '1' enables dine-in / on-table
 *        allow_coupons                → '1' shows the coupon box (OptionsDrop::allow_coupons)
 *        customer_auth_mode           → 'optional' (guest checkout allowed)
 *        menu_languages / default_language → storefront language switcher
 *  · Coupons (`coupons`) have NO restaurant/post column — a food-order coupon is
 *    resolved by OWNER: CouponService::resolve() does
 *    `Coupon::where('code', …)->where('user_id', $ownerId)`. All 18 demo stores
 *    share ONE owner (showcase-demo@example.com), so a single owner-scoped
 *    P1TEST10 row is redeemable on every one of them — that is what "on every
 *    store" means here. A per-store row set is impossible (no store column) and a
 *    branch-locked coupon would actually BREAK the storefront: the diner
 *    pre-flight (CouponService::validate) carries no branch_id, so a coupon with a
 *    non-null branch_id is rejected. Hence branch_id = null.
 *  · coupon_type MUST be 'percentage' explicitly — resolve() only computes a
 *    percentage discount when coupon_type === 'percentage'; a null/absent type
 *    falls through to the "fixed amount" branch and would treat 10 as SAR 10 off.
 *  · Business hours: `posts.timing` is free-text; the real open/closed gate is
 *    Branch::isOpenNow(), which reads the JSON `branches.hours` map keyed by
 *    Branch::DAYS (mon…sun) with {open,close,closed}. 24/7 = every day
 *    00:00–23:59, not-closed (empty hours also means "always open", but we write
 *    explicit hours so the intent is demonstrable).
 *  · Cash / COD is the PLATFORM DEFAULT payment method: a store with no online
 *    payment gateway installed accepts cash. There is no per-store StoreOption to
 *    toggle it (payment_gateways is a global install catalog), and the demo
 *    stores install no gateway — so cash is already enabled and nothing needs to
 *    be seeded for it. Documented here so its absence is not mistaken for a gap.
 *  · Tables (`store_tables`): area_type/shape/hk_status/oo_status/seats all
 *    carry DB defaults (table/square/clean/none/4); `status` does not, so it is
 *    always set. Same firstOrNew/forceFill idiom as PosV2DemoSeeder.
 *
 * Run:  php artisan db:seed --class=Juwri\\Modules\\Storefront\\Database\\Seeders\\E2ePlatformSeeder
 */
class E2ePlatformSeeder extends Seeder
{
    /** The one owner every demo store (17 modern + 2 display-only) lives under. */
    private const OWNER_EMAIL = 'showcase-demo@example.com';

    /** The shared platform-E2E coupon. */
    private const COUPON_CODE = 'P1TEST10';

    /**
     * First-party DISPLAY-ONLY templates (KeptTemplates::firstParty minus MODERN
     * minus folio) that ThemeShowcaseSeeder does not build a store for. We build
     * their demo stores here so all 18 kept storefronts are demoable. (The legacy
     * flipbook template and its demo store were removed in juwri Phase 0 WI-03.)
     *
     * @var list<string>
     */
    private const DISPLAY_ONLY = ['pageview'];

    /**
     * Real food photos that ship in database/seeders/assets/showcase/<key>.jpg and
     * are copied into storage/menu on seed — the exact convention ThemeShowcaseSeeder
     * uses, so the display stores render real images, not dead placeholders. Twelve
     * keys → 6 items per category × 2 categories, none repeated.
     *
     * @var list<string>
     */
    private const ITEM_PHOTOS = [
        'burger-classic', 'caesar-2', 'bruschetta', 'bibimbap', 'bowl-grain', 'ceviche',
        'cake-chocolate', 'brownie', 'berry-pie', 'avocado-toast', 'charcuterie', 'brisket',
    ];

    public function run(): void
    {
        // 0. Guarantee an ACTIVE `ar` language exists. The whole platform E2E run
        //    is Arabic-only, and the storefront only renders a menu language that
        //    has an active `languages` row (User\StoreController@publicView filters
        //    menu_languages by `active=1`). A prod DB copy that never activated
        //    Arabic would silently render every store in English (dir=ltr) and make
        //    `/ar/...` 404, failing every ar journey. This is idempotent and only
        //    ADDS/activates `ar`; it touches nothing else.
        $this->ensureArabicLanguage();

        // 1. Roll up every platform demo-data seeder (task order). Each is guarded
        //    by class_exists; E2eAdminSeeder additionally needs its throwaway creds
        //    or it throws by design, so it is only called when they are present
        //    (never under the test harness).
        $this->callSubSeeders();

        // 2. The shared showcase owner. ThemeShowcaseSeeder (called above) already
        //    created it; re-ensured here so this seeder also works standalone.
        $owner = $this->owner();

        // 3. The DISPLAY-ONLY demo store (pageview).
        foreach (self::DISPLAY_ONLY as $template) {
            $this->buildDisplayStore($owner, $template);
        }

        // 4. One owner-scoped P1TEST10 percentage coupon (redeemable on all 18).
        $this->seedCoupon($owner);

        // 5. The demoable baseline on every demo store (all 18).
        $this->enforceDemoBaseline($owner);

        // 6. E2E customer fixtures for account journeys (A7).
        $this->seedE2eCustomers($owner);

        $this->command?->info('E2ePlatformSeeder: '.count($this->demoSlugs()).' demo stores baselined; P1TEST10 coupon on owner '.$owner->id.'.');
    }

    /** Every {template}-demo slug this seeder governs: 17 modern + 1 display-only. */
    private function demoSlugs(): array
    {
        return array_map(
            static fn (string $t): string => $t.'-demo',
            [...KeptTemplates::MODERN, ...self::DISPLAY_ONLY]
        );
    }

    /* ============================ SUB-SEEDERS ============================ */

    private function callSubSeeders(): void
    {
        // Task-mandated order. Looked up by name in the module seeder registry (P1-11): the Pos, Marketplace and
        // Platform seeders sit in other modules and must not be imported here.
        foreach (['ThemePackDemoSeeder', 'ThemeShowcaseSeeder', 'StorefrontShowcaseSeeder', 'PosV2DemoSeeder', 'FusionResellerDemoSeeder'] as $name) {
            if ($class = ModuleSeeders::find($name)) {
                $this->call($class);
            }
        }

        // E2eAdminSeeder REFUSES to run without E2E_ADMIN_EMAIL + E2E_ADMIN_PASS
        // (it mints a superadmin — no default credential by design). Only call it
        // when the operator supplied throwaway creds; the sqlite test never does,
        // so it is skipped there while the rest of the seeder still runs.
        if (($class = ModuleSeeders::find('E2eAdminSeeder')) && env('E2E_ADMIN_EMAIL') && env('E2E_ADMIN_PASS')) {
            $this->call($class);
        }
    }

    /* ============================== OWNER ============================== */

    private function owner(): User
    {
        // firstOrCreate (not updateOrCreate) so a re-run never rehashes the
        // password or churns the row when ThemeShowcaseSeeder already made it.
        return User::firstOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'username' => 'themeshowcase',
                'name' => 'Theme Showcase Owner',
                'password' => Hash::make('showcase-demo-pass'),
                'user_type' => 'user',
                'group_id' => 2,
                'status' => 1,
                'country_code' => 'SA',
                'country' => 'Saudi Arabia',
                'email_verified_at' => now(),
            ]
        );
    }

    /* ========================= DISPLAY-ONLY STORES ========================= */

    /**
     * Build one DISPLAY-ONLY demo store (pageview): a published store
     * with 2 categories × 6 real-photo items, guest checkout, Arabic offered, and
     * the display template selected. Mirrors ThemeShowcaseSeeder::buildBasicStore.
     */
    private function buildDisplayStore(User $owner, string $template): void
    {
        $slug = $template.'-demo';
        $title = ucfirst($template).' Showcase';

        $store = Store::updateOrCreate(
            ['slug' => $slug],
            [
                'user_id' => $owner->id,
                'title' => $title,
                'sub_title' => 'Display-only theme demo',
                'description' => $title.' — a display-only showcase store for the '.$template.' template.',
                'color' => '#334155',
                'address' => 'King Fahd Rd, Riyadh',
                'phone' => '966500000000',
                'catalog_visible' => 1,   // published / visible on the storefront
                'is_verified' => true,
                // Logo/cover live under storage/store/logo|cover (the dashboard
                // list + storefront layout read them there), NOT storage/menu.
                'main_image' => $this->photoInto('burger-classic', 'store/logo'),
                'cover_image' => $this->photoInto('bar-interior', 'store/cover'),
            ]
        );
        $rid = $store->id;

        foreach ([
            'store_template' => $template,
            'demo_showcase' => '1',
            'restaurant_on_table_order' => '1',
            'restaurant_takeaway_order' => '1',
            'restaurant_delivery_order' => '1',
            'allow_coupons' => '1',
            'customer_auth_mode' => 'optional',
            // Arabic default so ar is unmistakably present; English offered.
            'menu_languages' => json_encode(['ar', 'en']),
            'default_language' => 'ar',
        ] as $name => $value) {
            StoreOption::updateStoreOption($rid, $name, $value);
        }

        Branch::updateOrCreate(
            ['store_id' => $rid, 'name' => 'Main Branch'],
            [
                'address' => $title.', Riyadh', 'phone' => '966500000010',
                'latitude' => 24.7136, 'longitude' => 46.6753, 'timezone' => 'Asia/Riyadh',
                'active' => true, 'accepting_orders' => true, 'position' => 0,
                'hours' => $this->hours247(),
            ]
        );

        // 2 categories × 6 items, drawn from the real-photo pool.
        $catalog = [
            'Signatures' => array_slice(self::ITEM_PHOTOS, 0, 6),
            'Desserts & Drinks' => array_slice(self::ITEM_PHOTOS, 6, 6),
        ];
        $names = [
            'burger-classic' => 'Classic Burger', 'caesar-2' => 'Caesar Salad',
            'bruschetta' => 'Bruschetta', 'bibimbap' => 'Bibimbap Bowl',
            'bowl-grain' => 'Grain Bowl', 'ceviche' => 'Ceviche',
            'cake-chocolate' => 'Chocolate Cake', 'brownie' => 'Fudge Brownie',
            'berry-pie' => 'Berry Pie', 'avocado-toast' => 'Avocado Toast',
            'charcuterie' => 'Charcuterie Board', 'brisket' => 'Smoked Brisket',
        ];
        $prices = [
            'burger-classic' => 42, 'caesar-2' => 34, 'bruschetta' => 28, 'bibimbap' => 46,
            'bowl-grain' => 38, 'ceviche' => 52, 'cake-chocolate' => 26, 'brownie' => 22,
            'berry-pie' => 24, 'avocado-toast' => 30, 'charcuterie' => 58, 'brisket' => 64,
        ];

        $catPos = 0;
        foreach ($catalog as $catName => $keys) {
            $cat = MenuCategory::updateOrCreate(
                ['store_id' => $rid, 'name' => $catName],
                ['position' => $catPos, 'active' => '1']
            );
            foreach ($keys as $i => $key) {
                Menu::updateOrCreate(
                    ['store_id' => $rid, 'name' => $names[$key]],
                    [
                        'category_id' => $cat->id,
                        'description' => $names[$key].' — house favourite.',
                        'price' => $prices[$key],
                        'image' => $this->photoInto($key, 'menu'),
                        'is_halal' => true,
                        'is_popular' => $i === 0,
                        'active' => '1',
                        'position' => $i,
                        'translations' => ['ar' => ['title' => $names[$key]]],
                    ]
                );
            }
            $catPos++;
        }
    }

    /* ============================== COUPON ============================== */

    /**
     * One owner-scoped P1TEST10: 10% percentage, active (no expiry, unlimited),
     * redeemable on every store this owner holds (see class docblock for why this
     * is one row, not nineteen). firstOrCreate + a stable-field re-assert keeps it
     * idempotent WITHOUT resetting the `used` counter on re-run.
     */
    private function seedCoupon(User $owner): void
    {
        $coupon = Coupon::firstOrCreate(
            ['user_id' => $owner->id, 'code' => self::COUPON_CODE],
            [
                'percentage' => 10,
                'coupon_type' => 'percentage',
                'branch_id' => null,        // NOT branch-locked (storefront pre-flight carries no branch_id)
                'applies_to' => 'all',
                'limit' => 0,               // 0 = unlimited sentinel
                'used' => 0,
                'expiry_at' => null,        // never expires → always "active"
            ]
        );

        // Re-assert the discount shape on every run (never touch `used`).
        $coupon->forceFill([
            'percentage' => 10,
            'coupon_type' => 'percentage',
            'branch_id' => null,
        ])->save();
    }

    /* ========================= DEMO BASELINE ========================= */

    /**
     * Ensure every demo store (17 modern + 2 display-only) is fully demoable:
     * delivery + pickup + dine-in on, coupons on, guest checkout, Arabic present,
     * at least one table, and 24/7 branch hours. Cash/COD needs nothing (see class
     * docblock). Only touches this owner's demo stores — never a real store or a
     * global setting.
     */
    private function enforceDemoBaseline(User $owner): void
    {
        foreach ($this->demoSlugs() as $slug) {
            $store = Store::where('slug', $slug)->where('user_id', $owner->id)->first();
            if (! $store) {
                $this->command?->warn("E2ePlatformSeeder: {$slug} missing — baseline skipped.");

                continue;
            }
            $rid = $store->id;

            // Order types + coupon box + guest checkout.
            foreach ([
                'restaurant_delivery_order' => '1',
                'restaurant_takeaway_order' => '1',
                'restaurant_on_table_order' => '1',
                'allow_coupons' => '1',
                'customer_auth_mode' => 'optional',
            ] as $name => $value) {
                StoreOption::updateStoreOption($rid, $name, $value);
            }

            $this->ensureArabic($rid);
            $this->ensure247Hours($rid, $store->title);
            $this->ensureTable($rid);
        }
    }

    /**
     * Guarantee an active `ar` row in the `languages` table (RTL, Arabic). The
     * storefront only offers/renders a menu language whose `languages` row is
     * `active=1`; without it, stores default_language=ar still render English.
     */
    private function ensureArabicLanguage(): void
    {
        Language::updateOrCreate(
            ['code' => 'ar'],
            ['direction' => 'rtl', 'name' => 'Arabic', 'active' => 1]
        );
    }

    /** Guarantee 'ar' is in the store's language list (non-destructive merge). */
    private function ensureArabic(int $rid): void
    {
        $stored = StoreSettings::legacy($rid, 'menu_languages');
        $langs = $stored !== null ? (json_decode($stored, true) ?: []) : [];
        if (! in_array('ar', $langs, true)) {
            $langs = $langs ? array_values(array_unique([...$langs, 'ar'])) : ['ar', 'en'];
            StoreSettings::set($rid, 'menu_languages', json_encode($langs));
        }
        // A default_language must exist for the switcher to resolve one.
        if (! StoreSettings::has($rid, 'default_language')) {
            StoreSettings::set($rid, 'default_language', 'ar');
        }
    }

    /**
     * Put 24/7 hours on every branch of the store (creating a Main Branch if the
     * store somehow has none) so Branch::isOpenNow() is always true.
     */
    private function ensure247Hours(int $rid, string $title): void
    {
        $branches = Branch::where('store_id', $rid)->get();
        if ($branches->isEmpty()) {
            Branch::create([
                'store_id' => $rid, 'name' => 'Main Branch',
                'address' => $title.', Riyadh', 'phone' => '966500000010',
                'latitude' => 24.7136, 'longitude' => 46.6753, 'timezone' => 'Asia/Riyadh',
                'active' => true, 'accepting_orders' => true, 'position' => 0,
                'hours' => $this->hours247(),
            ]);

            return;
        }
        foreach ($branches as $branch) {
            $branch->forceFill([
                'hours' => $this->hours247(),
                'active' => true,
                'accepting_orders' => true,
            ])->save();
        }
    }

    /** At least one dine-in table so the on-table order flow has a target. */
    private function ensureTable(int $rid): void
    {
        $branchId = Branch::where('store_id', $rid)->orderBy('id')->value('id');
        StoreTable::firstOrCreate(
            ['store_id' => $rid, 'table_no' => 1],
            [
                'branch_id' => $branchId,
                'area_type' => 'table',
                'label' => 'T1',
                'seats' => 4,
                'status' => 'free',   // status has no DB default; the rest do
            ]
        );
    }

    /** A 24/7 hours map keyed by Branch::DAYS (mon…sun). */
    private function hours247(): array
    {
        return array_fill_keys(
            Branch::DAYS,
            ['open' => '00:00', 'close' => '23:59', 'closed' => false]
        );
    }

    /* ============================== MEDIA ============================== */

    /**
     * Copy a shipped showcase photo (database/seeders/assets/showcase/$key.jpg)
     * into public/storage/<dir> and return its bare filename — the exact
     * convention ThemeShowcaseSeeder uses. Idempotent (skips if already copied)
     * and safe when the asset is absent (returns the filename regardless, so the
     * DB row is stable even on a host without the asset pack).
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

    /* ========================= E2E CUSTOMERS ========================= */

    /**
     * Create E2E customer fixtures for the crave-demo store (A7 account journey).
     * Idempotent via firstOrCreate on email.
     */
    private function seedE2eCustomers(User $owner): void
    {
        $store = Store::where('slug', 'crave-demo')->where('user_id', $owner->id)->first();
        if (! $store) {
            return; // crave-demo doesn't exist, skip customers
        }

        // A7 journey customer fixture
        Customer::firstOrCreate(
            ['email' => 'e2e-customer@example.test'],
            [
                'store_id' => $store->id,
                'name' => 'محمد علي', // Muhammad Ali in Arabic
                'phone' => '966501234567',
                'password' => Hash::make('E2ePass123'),
            ]
        );
    }
}
