<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * T7 — legacy-template storefront render/checkout sweep.
 *
 * SparseOptionsRenderTest already sweeps the public MENU page (route
 * 'publicView') for every theme on disk, catching an unguarded
 * $postOptions->key read on the menu. But the deeper diner-facing surfaces —
 * the in-page CHECKOUT/cart sections, the order-TRACK page and the customer
 * ACCOUNT page — were only ever swept for a single theme: RouteSmokeSweepTest
 * pins its store to store_template='juwri'. So a legacy theme whose track
 * or account blade reads an unguarded $postOptions->key fatals in prod with no
 * CI net (CONVENTIONS §theme-usage-skew — ~3,391 stores run legacy themes).
 *
 * This sweep exercises, on a SPARSE-options store (only the template slug set),
 * for each keep-list legacy theme:
 *   1. publicView  — the menu page, which for these single-page-app themes also
 *      renders the cart + checkout <section> blocks at page load. A checkout
 *      blade that reads $postOptions->key unguarded fatals here.
 *   2. restaurant.orderTrack — the theme's own track.blade.php (separate blade).
 *   3. customer.account       — the theme's own account.blade.php (separate blade,
 *      reached only when a customer is authenticated).
 *
 * Scope: the sweep iterates the EXPLICIT keep-list of 17 templates rather than
 * an off-disk glob. Every other store_templates/* dir is being retired, so a
 * glob would red the suite the day a retired theme's dir is deleted. Adding the
 * cover for a new keep-theme is a one-line edit to KEEP_LIST.
 */
class LegacyTemplateStorefrontSweepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The 17 templates that survive the store_templates cull. Sweeping only
     * these keeps the test green as the retired themes are deleted.
     *
     * @var list<string>
     */
    private const KEEP_LIST = KeptTemplates::MODERN;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // Themes read $settings for "Powered by {site_title}" and branding.
        // Mirror the shared global the real app boots with (as SparseOptions does).
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

    /** @return array<string, array{0: string}> */
    public static function keepListProvider(): array
    {
        $themes = [];
        foreach (self::KEEP_LIST as $slug) {
            $themes[$slug] = [$slug];
        }

        return $themes;
    }

    #[DataProvider('keepListProvider')]
    public function test_storefront_surfaces_render_with_sparse_options(string $theme): void
    {
        // Guard the keep-list against silent drift: every listed theme must still
        // ship the blades this sweep asserts. A missing dir means the cull
        // removed a theme without updating KEEP_LIST.
        $base = dirname(__DIR__, 3).'/resources/views/store_templates/'.$theme;
        $this->assertFileExists($base.'/index.blade.php', "Keep-list theme [{$theme}] has no index.blade.php — update KEEP_LIST.");
        $this->assertFileExists($base.'/track.blade.php', "Keep-list theme [{$theme}] has no track.blade.php — update KEEP_LIST.");
        $this->assertFileExists($base.'/account.blade.php', "Keep-list theme [{$theme}] has no account.blade.php — update KEEP_LIST.");

        [$owner, $store] = $this->makeOwnerAndRestaurant();

        // The ONLY option set is the template slug — every other key is absent,
        // the exact state that trips an unguarded $postOptions->key read.
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);

        // One live menu item so a theme that loops the menu has something to render.
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        // 1) Menu page — also renders the in-page cart + checkout sections.
        $menuResp = $this->get(route('publicView', $store->slug));
        $this->assertLessThan(500, $menuResp->status(),
            "Theme [{$theme}] menu/checkout returned {$menuResp->status()} on a sparse store — likely an unguarded \$postOptions->key read in the menu/cart/checkout blade.");

        // 2) Order-track page — a delivery order with a track token, the theme's
        //    own track.blade.php.
        $order = Order::create([
            'store_id' => $store->id,
            'customer_name' => 'Jane Diner',
            'type' => 'delivery',
            'status' => 'pending',
            'track_token' => Str::random(32),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);

        $trackResp = $this->get(route('store.orderTrack', $order->track_token));
        $this->assertLessThan(500, $trackResp->status(),
            "Theme [{$theme}] order-track returned {$trackResp->status()} on a sparse store — likely an unguarded \$postOptions->key read in track.blade.php.");

        // 3) Customer account page — the theme's own account.blade.php, reached
        //    only when a customer is authenticated for THIS restaurant.
        $customer = Customer::create([
            'store_id' => $store->id,
            'name' => 'Jane Diner',
            'email' => 'jane'.Str::random(5).'@example.com',
            'phone' => '15551234',
            'password' => bcrypt('secret-'.Str::random(6)),
            'email_verified_at' => now(),
        ]);

        $accountResp = $this->actingAs($customer, 'customer')->get(route('customer.account', $store->id));
        $this->assertLessThan(500, $accountResp->status(),
            "Theme [{$theme}] account page returned {$accountResp->status()} on a sparse store — likely an unguarded \$postOptions->key read in account.blade.php.");
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
