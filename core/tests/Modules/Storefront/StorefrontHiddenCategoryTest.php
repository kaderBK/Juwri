<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The storefront must never render an item whose category is inactive or
 * missing. This is the customer-facing half of the barcoder-orphan repair:
 * orphan items (category row gone) used to render on the public menu while
 * being unreachable from the merchant's category-grouped editor — chef-shafi's
 * $4 "كوردن بلو" complaint. The filter lives on the single $total_menus
 * assembly in StoreController::publicView, so every theme honours it.
 */
class StorefrontHiddenCategoryTest extends TestCase
{
    use RefreshDatabase {
        beginDatabaseTransaction as refreshDatabaseBeginTransaction;
    }

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // WI-M12: build the layout context through the sanctioned helper instead
        // of hand-rolling the settings double (ViewComposerContractTest cap).
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /**
     * FK relaxation must land AFTER the connection exists but BEFORE
     * RefreshDatabase opens its wrapper transaction (a PRAGMA inside a
     * transaction is a silent no-op). This runs exactly there, per test, so it
     * holds under --parallel too — unlike env-var tricks, which phpunit.xml's
     * worker bootstrap outranks.
     */
    protected function beginDatabaseTransaction(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        $this->refreshDatabaseBeginTransaction();
    }

    private function restaurantWithMenu(): array
    {
        // The owner needs a real plan row: publicView checks $store->user->plan()->status.
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'allow_ordering' => 1, 'table_qr' => 0, 'allow_pro' => '0',
                'allow_advanced' => '0', 'allow_on_free' => '0', 'allow_on_try' => '0',
                'allow_on_main' => '0', 'allow_viewonly' => '0', 'allow_custom' => '0',
                'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::query()->create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');

        return [$store, $user];
    }

    public function test_public_menu_hides_items_with_inactive_or_missing_category(): void
    {
        [$store] = $this->restaurantWithMenu();

        $live = MenuCategory::query()->create([
            'store_id' => $store->id, 'name' => 'Mains', 'parent' => null, 'position' => 1, 'active' => '1',
        ]);
        $hidden = MenuCategory::query()->create([
            'store_id' => $store->id, 'name' => 'Uncategorized', 'parent' => null, 'position' => 9999, 'active' => '0',
        ]);

        Menu::query()->create(['store_id' => $store->id, 'category_id' => $live->id, 'name' => 'Live Burger', 'price' => 6.4, 'active' => '1', 'position' => 1]);
        Menu::query()->create(['store_id' => $store->id, 'category_id' => $hidden->id, 'name' => 'Hidden Bucket Item', 'price' => 4, 'active' => '1', 'position' => 2]);
        // An orphan category id can only exist where foreign keys are not enforced (sqlite).
        if (DB::connection()->getDriverName() === 'sqlite') {
            Menu::query()->create(['store_id' => $store->id, 'category_id' => 987654, 'name' => 'Orphan Cordon Bleu', 'price' => 4, 'active' => '1', 'position' => 3]);
        }
        // NULL category is NOT hidden — category-less items are long-standing
        // intended behaviour (MenuSchedulesTest grey-out path relies on it).
        Menu::query()->create(['store_id' => $store->id, 'category_id' => null, 'name' => 'Null Category Snack', 'price' => 1, 'active' => '1', 'position' => 4]);

        $response = $this->get(route('publicView', $store->slug));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('Live Burger', $html);
        $this->assertStringContainsString('Null Category Snack', $html);
        $this->assertStringNotContainsString('Hidden Bucket Item', $html);
        $this->assertStringNotContainsString('Orphan Cordon Bleu', $html);
    }
}
