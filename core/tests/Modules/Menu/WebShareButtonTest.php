<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F14-2 — per-store Web Share control, gated behind the `share.fallback_button`
 * theme setting (default OFF).
 *
 * The shared floating fallback pill (_partials/share, `id="mbShareBtn"`) has been
 * retired for the 17 modern themes: it was @included from the shared layout that
 * every page extends, so it surfaced on Track Order / account / wallet too, not
 * just the menu. Each theme now carries a NATIVE share control instead — the 13
 * in-menu themes render `id="mbShareRow"` (crave: `id="cvShareBtn"`) on their
 * profile view, and the four standalone-profile themes render one on their own
 * profile page. orbit (used here) is a native-row theme, so the contract is now:
 * the toggle emits the theme's own `data-web-share` control and never the pill.
 */
class WebShareButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

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

    private function makeStore(string $template = 'orbit'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0', 'allow_on_try' => '0',
                'allow_on_main' => '0', 'allow_viewonly' => '0', 'allow_custom' => '0', 'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        // orbit renders its native share row at the foot of the cart view, which
        // only exists when ordering is on ($allow_order). Enable a single order
        // type so the toggled-on control is reachable in the rendered page.
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', 1);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        return $store;
    }

    public function test_share_button_absent_by_default(): void
    {
        $store = $this->makeStore('orbit');

        $res = $this->get(route('publicView', $store->slug));
        $res->assertOk();
        // No share control renders until the owner turns the toggle on...
        $res->assertDontSeeHtml('id="mbShareRow"');
        // ...and the retired floating fallback pill never renders at all.
        $res->assertDontSeeHtml('id="mbShareBtn"');
    }

    public function test_share_button_renders_when_toggled_on(): void
    {
        $store = $this->makeStore('orbit');
        ThemeSetting::create([
            'store_id' => $store->id,
            'template' => 'orbit',
            'settings' => ['share.fallback_button' => true],
        ]);

        $res = $this->get(route('publicView', $store->slug));
        $res->assertOk();
        // orbit's NATIVE share control renders on its profile view when the
        // toggle is on: a data-web-share button (its navigator.share handler
        // lives in the theme's external script.js, not the page HTML).
        $res->assertSeeHtml('id="mbShareRow"');
        $res->assertSeeHtml('data-web-share');
        // The retired fixed-position pill must NOT be emitted — removing its
        // shared-layout include is what stopped it leaking onto Track Order.
        $res->assertDontSeeHtml('id="mbShareBtn"');
    }

    public function test_share_fragment_is_in_the_theme_schema(): void
    {
        $schema = ThemeCustomizerService::schemaFor('orbit');
        $json = json_encode($schema);
        $this->assertStringContainsString('share.fallback_button', $json);
    }
}
