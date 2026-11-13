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
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * B2 (platform E2E, lane B) — the menu a merchant builds must be exactly what
 * the customer storefront renders, driven HTTP-through-the-controller on the
 * modern `crave` template (App\Http\Controllers\User\StoreController@publicView).
 *
 * The load-bearing case is the documented "menu-skips-cats" bug: hiding a
 * category must drop that category AND its items, but must NEVER drop the
 * category that FOLLOWS it. Earlier theme runs shipped a loop that, on skipping
 * a hidden/empty category, advanced past the next one too. The guard now lives
 * at the query level — Post::menu_categories() filters `active='1'` and
 * Menu::visibleOnStorefront() filters items whose category is inactive — so a
 * three-category layout with the MIDDLE one hidden is the discriminating fixture:
 * remove either guard and the hidden category (or its item) reappears and this
 * test goes red (verified by mutation).
 *
 * Also frozen here, because B2 builds them and every theme must reflect them:
 * the out-of-stock flag reaches the payload, and an item's REQUIRED variant
 * group + OPTIONAL extras group reach the payload the theme renders from.
 *
 * Complements StorefrontHiddenCategoryTest (which proves inactive/orphan items
 * do not render) with the *category ordering* + build-surface assertions.
 */
class StorefrontMenuBuildB2Test extends TestCase
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

        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    protected function beginDatabaseTransaction(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        $this->refreshDatabaseBeginTransaction();
    }

    private function craveStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'category_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0',
                'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0',
                'allow_custom' => '0', 'allow_onlybio' => '0',
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

        return $store;
    }

    private function category(Store $store, string $name, int $position, string $active): MenuCategory
    {
        return MenuCategory::query()->create([
            'store_id' => $store->id, 'name' => $name, 'parent' => null,
            'position' => $position, 'active' => $active,
        ]);
    }

    private function item(Store $store, MenuCategory $cat, string $name, array $extra = []): Menu
    {
        return Menu::query()->create(array_merge([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name,
            'price' => 10, 'active' => '1', 'position' => 1, 'type' => 'veg',
        ], $extra));
    }

    public function test_crave_storefront_reflects_the_built_menu_and_never_skips_the_category_after_a_hidden_one(): void
    {
        $store = $this->craveStore();

        // Three top-level categories in position order A(1) → B(2,HIDDEN) → C(3).
        $catA = $this->category($store, 'MainsCatB2', 1, '1');
        $catB = $this->category($store, 'SecretCatB2', 2, '0');   // hidden
        $catC = $this->category($store, 'DessertsCatB2', 3, '1');

        // catA: a plain item + the modifier item (required variant + optional extras).
        $this->item($store, $catA, 'MainsBurgerB2');
        $combo = $this->item($store, $catA, 'ModComboB2');

        $vo = MenuVariantOption::query()->create([
            'store_id' => $store->id, 'menu_id' => $combo->id,
            'title' => 'SizeGroupB2', 'options' => ['SizeSmallB2', 'SizeLargeB2'],
            'position' => 1, 'active' => 1,
        ]);
        MenuVariant::query()->create([
            'menu_id' => $combo->id, 'price' => 34, 'options' => [(string) $vo->id => 1], 'active' => 1, 'position' => 1,
        ]);
        MenuExtra::query()->create([
            'store_id' => $store->id, 'menu_id' => $combo->id, 'title' => 'ExtraCheeseB2', 'price' => 5, 'active' => 1, 'position' => 1,
        ]);

        // catB (hidden): its item must vanish with the category.
        $this->item($store, $catB, 'HiddenDishB2');

        // catC (the category AFTER the hidden one): a plain item + a sold-out item.
        $this->item($store, $catC, 'DessertKunafaB2');
        $this->item($store, $catC, 'SoldOutCakeB2', ['is_sold_out' => 1]);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        // Visible categories render as chips; the hidden one does NOT.
        $this->assertStringContainsString('MainsCatB2', $html, 'category A chip missing');
        $this->assertStringContainsString('DessertsCatB2', $html, 'category C chip missing');
        $this->assertStringNotContainsString('SecretCatB2', $html, 'hidden category B leaked a chip');

        // THE bug guard: the category AFTER the hidden one is not skipped, and the
        // surviving order is preserved (A before C).
        $posA = strpos($html, 'MainsCatB2');
        $posC = strpos($html, 'DessertsCatB2');
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posC);
        $this->assertLessThan($posC, $posA, 'category C (after the hidden B) was dropped or reordered — menu-skips-cats regression');

        // Visible items render; the hidden category's item does not.
        $this->assertStringContainsString('MainsBurgerB2', $html);
        $this->assertStringContainsString('ModComboB2', $html);
        $this->assertStringContainsString('DessertKunafaB2', $html);
        $this->assertStringContainsString('SoldOutCakeB2', $html);
        $this->assertStringNotContainsString('HiddenDishB2', $html, 'hidden category item leaked onto the storefront');

        // Out-of-stock reaches the payload the theme renders from.
        $this->assertStringContainsString('"is_sold_out":true', $html, 'sold-out flag missing from the storefront payload');

        // The REQUIRED variant group (its option label) + the OPTIONAL extras group
        // (its title) both reach the payload.
        $this->assertStringContainsString('SizeLargeB2', $html, 'required variant modifier group missing from payload');
        $this->assertStringContainsString('ExtraCheeseB2', $html, 'optional extras modifier group missing from payload');
    }
}
