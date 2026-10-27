<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Sparse-options render smoke test.
 *
 * store_options() builds its object only from the option rows a store has
 * actually saved, so any theme that reads $postOptions->someKey without a
 * guard fatals ("Undefined property: stdClass::$someKey") the moment a store
 * has not set that key. This is the exact class of bug that took /orders down
 * for the bought theme (J9) (an unguarded $postOptions->restaurant_delivery_charge).
 *
 * PHPStan/Larastan does not see compiled Blade, so this render pass is the net
 * for that class: it points every diner-facing theme at a store with the
 * MINIMUM viable options (only the template slug is set — nothing else) and
 * asserts the public menu page does not 500. A crash here means a theme reads
 * an option key without an @/!empty/?? guard.
 */
class SparseOptionsRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // Themes read $settings for "Powered by {site_title}" and branding.
        // Mirror the shared global the real app boots with.
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /**
     * Every theme with a public index.blade.php, discovered off disk so a new
     * theme is covered automatically the day it lands.
     *
     * @return array<string, array{0: string}>
     */
    public static function themeProvider(): array
    {
        // Data providers evaluate before the Laravel app boots, so the
        // resource_path() helper is unavailable here — resolve off __DIR__
        // (core/tests/Feature → core) instead.
        $glob = dirname(__DIR__, 3).'/resources/views/store_templates/*/index.blade.php';
        $paths = glob($glob) ?: [];
        $themes = [];
        foreach ($paths as $path) {
            $slug = basename(dirname($path));
            $themes[$slug] = [$slug];
        }
        ksort($themes);

        return $themes;
    }

    #[DataProvider('themeProvider')]
    public function test_theme_renders_with_sparse_options(string $theme): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();

        // The ONLY option set is the template slug — every other key is absent,
        // which is exactly the state that trips unguarded $postOptions reads.
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);

        // One live menu item so a theme that loops the menu has something to
        // render (an empty grid would not exercise the item/price read paths).
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $this->assertLessThan(
            500,
            $response->status(),
            "Theme [{$theme}] returned {$response->status()} rendering a sparse-options store — likely an unguarded \$postOptions->key read."
        );
    }

    /**
     * The owner Orders screen is the page that actually took the 500: the bought-theme
     * order-row read $postOptions->restaurant_delivery_charge unguarded, so a
     * delivery order on a store that never set a delivery charge fataled. This
     * pins that exact path — sparse options + a delivery order must render.
     */
    public function test_owner_orders_page_renders_delivery_order_with_sparse_options(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();
        // No store_options at all — restaurant_delivery_charge is absent, which
        // is the state that tripped the unguarded read.

        // A live menu line, so the order survives the Orders screen's liveness
        // check (orders that price to 0 because their menu rows are gone are
        // dropped before the row renders).
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        $order = Order::create([
            'store_id' => $store->id,
            'customer_name' => 'Jane Diner',
            'type' => 'delivery',
            'status' => 'pending', // anything but 'unpaid', which the list hides
            'track_token' => Str::random(32),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);

        $response = $this->actingAs($owner)->get(route('stores.orders', $store->id));

        // Guard against a silent pass: the delivery order-row must actually be
        // on the page, otherwise the render never reaches the read we protect.
        $response->assertSeeHtml('Jane Diner');

        $this->assertLessThan(
            500,
            $response->status(),
            "Owner Orders page returned {$response->status()} for a delivery order on a sparse-options store — an unguarded \$postOptions->key read regressed."
        );
    }

    private function makeOwnerAndRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4),
            'description' => 'T',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5,
                'scan_limit' => 999,
                'menu_limit' => 999,
                'allow_ordering' => 1,
                'table_qr' => 0,
                'allow_pro' => '0',
                'allow_advanced' => '0',
                'allow_on_free' => '0',
                'allow_on_try' => '0',
                'allow_on_main' => '0',
                'allow_viewonly' => '0',
                'allow_custom' => '0',
                'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $store];
    }
}
