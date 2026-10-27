<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\Coupon;
use App\Models\LoyaltyProgram;
use App\Models\MenuCategory;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Database\Seeders\HotelDemoSeeder;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Database\Seeders\DemoCatalogSeeder;
use Juwri\Modules\Storefront\Database\Seeders\ThemePackDemoSeeder;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoOwnerPlan;
use Tests\Support\PinsMiddayClock;
use Tests\TestCase;

/**
 * J62-3: the demo catalogue seeder — one owner for every demo store, a multi-branch
 * Arabic-first restaurant, the QR tables, the coupons and the loyalty programs.
 *
 * The whole demo run is seeded TWICE: the seeder must update, never duplicate.
 */
class DemoCatalogSeederTest extends TestCase
{
    use PinsMiddayClock;
    use RefreshDatabase;

    /**
     * DemoAccountsSeeder arrives with the accounts job; the test runs with or without it.
     */
    private const DEMO_ACCOUNTS_SEEDER = 'Juwri\\Modules\\Storefront\\Database\\Seeders\\DemoAccountsSeeder';

    protected function setUp(): void
    {
        parent::setUp();

        // The owner the catalogue hangs on. DemoAccountsSeeder owns this row in the full
        // demo run; here it is created up front so the seeder has an owner to work with.
        User::updateOrCreate(
            ['email' => DemoOwnerPlan::OWNER_EMAIL],
            [
                'username' => 'demoowner',
                'name' => 'Demo Owner',
                'password' => Hash::make('demo-owner-password'),
                'user_type' => 'user',
                'group_id' => 2,
                'status' => 1,
                'country_code' => 'SA',
                'country' => 'Saudi Arabia',
                'email_verified_at' => now(),
            ]
        );
    }

    /**
     * The demo run, twice.
     */
    private function seedTwice(): void
    {
        foreach (range(1, 2) as $ignored) {
            $this->seed(ThemePackDemoSeeder::class);
            $this->seed(HotelDemoSeeder::class);
            $this->seedDemoAccounts();
            $this->seed(DemoCatalogSeeder::class);
        }
    }

    /**
     * Seed the accounts seeder when it is present (it is a separate job's file).
     */
    private function seedDemoAccounts(): void
    {
        $seeder = self::DEMO_ACCOUNTS_SEEDER;
        if (! class_exists($seeder)) {
            return;
        }

        $this->seed($seeder);
    }

    private function owner(): User
    {
        return User::where('email', DemoOwnerPlan::OWNER_EMAIL)->firstOrFail();
    }

    private function store(string $slug): Store
    {
        return Store::where('slug', $slug)->firstOrFail();
    }

    public function test_it_builds_one_multi_branch_store_with_a_real_menu(): void
    {
        $this->seedTwice();

        $this->assertSame(1, Store::where('slug', DemoOwnerPlan::STORES['branches'])->count());
        $store = $this->store(DemoOwnerPlan::STORES['branches']);

        $this->assertSame('Demo Branches Grill', $store->title);
        $this->assertSame('مشوي الفروع التجريبي', $store->sub_title);
        $this->assertSame('ember', StoreSettings::legacy((int) $store->id, 'store_template'));

        // Three branches, different hours, one of them closed for orders.
        $branches = Branch::where('store_id', $store->id)->orderBy('position')->get();
        $this->assertCount(3, $branches);
        $this->assertSame(['Riyadh Olaya', 'Jeddah Tahlia', 'Dammam Corniche'], $branches->pluck('name')->all());
        $this->assertFalse($branches->firstWhere('name', 'Dammam Corniche')->accepting_orders);
        $this->assertTrue($branches->firstWhere('name', 'Riyadh Olaya')->accepting_orders);
        $this->assertNotSame(
            $branches->firstWhere('name', 'Riyadh Olaya')->hours,
            $branches->firstWhere('name', 'Jeddah Tahlia')->hours
        );

        // Three categories, thirteen items, variants and extras on real items.
        $this->assertSame(3, MenuCategory::where('store_id', $store->id)->count());
        $this->assertGreaterThanOrEqual(12, Menu::where('store_id', $store->id)->count());
        $this->assertGreaterThanOrEqual(
            3,
            Menu::where('store_id', $store->id)->whereHas('variantOptions')->count(),
            'at least three items carry variant groups'
        );
        $this->assertGreaterThanOrEqual(
            3,
            Menu::where('store_id', $store->id)->whereHas('extras')->count(),
            'at least three items carry extras'
        );

        // The order types the owner clicks through.
        foreach (['restaurant_on_table_order', 'restaurant_takeaway_order', 'restaurant_delivery_order', 'allow_coupons'] as $option) {
            $this->assertSame('1', StoreSettings::legacy((int) $store->id, $option), "{$option} is on");
        }
        $this->assertSame('optional', StoreSettings::legacy((int) $store->id, 'customer_auth_mode'));
    }

    public function test_every_demo_store_is_owned_by_the_demo_owner_and_tagged(): void
    {
        $this->seedTwice();

        $owner = $this->owner();

        foreach (DemoOwnerPlan::STORES as $slug) {
            $store = Store::where('slug', $slug)->first();
            if (! $store) {
                continue;
            }

            $this->assertSame((int) $owner->id, (int) $store->user_id, "{$slug} belongs to the demo owner");
            $this->assertSame(
                DemoOwnerPlan::TAG_VALUE,
                StoreSettings::legacy((int) $store->id, DemoOwnerPlan::TAG_OPTION),
                "{$slug} carries the demo tag"
            );
        }
    }

    public function test_the_coupons_are_seeded_once_each_for_the_demo_owner(): void
    {
        $this->seedTwice();

        $owner = $this->owner();

        $percent = Coupon::where('code', DemoOwnerPlan::COUPON_PERCENT)->get();
        $this->assertCount(1, $percent, 'the percentage coupon exists exactly once');
        $this->assertSame('percentage', $percent->first()->coupon_type);
        $this->assertEquals(10, $percent->first()->percentage);
        $this->assertSame((int) $owner->id, (int) $percent->first()->user_id);
        $this->assertNull($percent->first()->branch_id);
        $this->assertTrue($percent->first()->expiry_at->isFuture());

        $fixed = Coupon::where('code', DemoOwnerPlan::COUPON_FIXED)->get();
        $this->assertCount(1, $fixed, 'the fixed coupon exists exactly once');
        $this->assertSame('fixed', $fixed->first()->coupon_type);
        $this->assertEquals(5, $fixed->first()->percentage);
        $this->assertSame((int) $owner->id, (int) $fixed->first()->user_id);
        $this->assertTrue($fixed->first()->expiry_at->isFuture());
    }

    public function test_each_demo_store_has_one_active_loyalty_program(): void
    {
        $this->seedTwice();

        foreach ([DemoOwnerPlan::STORES['restaurant'], DemoOwnerPlan::STORES['branches']] as $slug) {
            $store = $this->store($slug);
            $programs = LoyaltyProgram::where('store_id', $store->id)->get();

            $this->assertCount(1, $programs, "{$slug} has exactly one loyalty program");
            $this->assertSame('points', $programs->first()->type);
            $this->assertTrue((bool) $programs->first()->active);
            $this->assertGreaterThan(0, (int) $programs->first()->reward_threshold);
        }
    }

    public function test_the_two_qr_restaurants_have_six_tables_each(): void
    {
        $this->seedTwice();

        foreach ([DemoOwnerPlan::STORES['restaurant'], DemoOwnerPlan::STORES['branches']] as $slug) {
            $store = $this->store($slug);
            $tables = StoreTable::where('store_id', $store->id)->where('area_type', 'table')->get();

            $this->assertCount(6, $tables, "{$slug} has six QR tables");
            $this->assertSame([1, 2, 3, 4, 5, 6], $tables->pluck('table_no')->sort()->values()->all());

            foreach ($tables as $table) {
                $this->assertNotEmpty($table->key, 'a table without a key has no QR link');
                $this->assertNotEmpty($table->status);
            }
        }
    }

    public function test_arabic_is_offered_everywhere_and_the_menus_carry_arabic_names(): void
    {
        $this->seedTwice();

        $this->assertTrue((bool) Language::where('code', 'ar')->value('active'), 'the Arabic language row is active');

        $restaurant = $this->store(DemoOwnerPlan::STORES['restaurant']);
        $languages = json_decode((string) StoreSettings::legacy((int) $restaurant->id, 'menu_languages'), true) ?: [];
        $this->assertContains('ar', $languages, 'the restaurant store offers Arabic');

        $branchStore = $this->store(DemoOwnerPlan::STORES['branches']);
        $branchLanguages = json_decode((string) StoreSettings::legacy((int) $branchStore->id, 'menu_languages'), true) ?: [];
        $this->assertContains('ar', $branchLanguages, 'the branch store offers Arabic');

        // Every item of the branch demo carries an Arabic title.
        foreach (Menu::where('store_id', $branchStore->id)->get() as $menu) {
            $this->assertNotEmpty($menu->translations?->ar?->title, "{$menu->name} has an Arabic name");
        }

        // The restaurant store's own English menu got Arabic names too.
        $shawarma = Menu::where('store_id', $restaurant->id)->where('name', 'Chicken Shawarma')->first();
        $this->assertNotNull($shawarma);
        $this->assertSame('شاورما دجاج', $shawarma->translations?->ar?->title);
    }

    public function test_the_seeder_is_a_no_op_without_the_demo_owner(): void
    {
        User::where('email', DemoOwnerPlan::OWNER_EMAIL)->delete();

        $this->seed(ThemePackDemoSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->assertSame(0, Store::where('slug', DemoOwnerPlan::STORES['branches'])->count());
    }
}
