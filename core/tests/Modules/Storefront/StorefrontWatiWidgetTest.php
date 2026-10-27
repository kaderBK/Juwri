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
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F6: the hardcoded third-party WATI (clare.ai) storefront chat widget is gated on
 * the `storefront_wati_widget` platform Option. It now defaults OFF — the built-in
 * wa.me channel is the sanctioned WhatsApp path, so an admin must opt IN to load the
 * external bubble. Enforced CSP always fails closed (the script is not allowlisted).
 */
class StorefrontWatiWidgetTest extends TestCase
{
    use RefreshDatabase;

    private const WATI = 'wati-integration-service.clare.ai';

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

    private function makeLegacyStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0, 'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0', 'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0', 'allow_custom' => '0', 'allow_onlybio' => '0'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234567', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'old-2-theme');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        return $store;
    }

    /** Default (no Option) now DROPS the widget — the external bubble is opt-in. */
    public function test_wati_widget_is_absent_by_default_on_legacy_theme(): void
    {
        $r = $this->makeLegacyStore();

        $this->get(route('publicView', $r->slug))->assertOk()->assertDontSeeHtml(self::WATI);
    }

    /** An admin who explicitly opts in ('1') still gets the clare.ai script. */
    public function test_wati_widget_renders_when_option_enabled(): void
    {
        $r = $this->makeLegacyStore();
        PlatformSettings::set('storefront_wati_widget', '1');
        config(['settings.storefront_wati_widget' => 1]); // provider ran before the Option existed
        // the third-party widget is dropped under an enforced CSP (default since the P4-17 follow-up): roll-back mode
        config(['csp.enforce' => false, 'csp.storefront_enforce' => 'off']);

        $this->get(route('publicView', $r->slug))->assertOk()->assertSeeHtml(self::WATI);
    }

    /** With the platform Option off, the clare.ai script is gone from the storefront. */
    public function test_wati_widget_is_absent_when_option_disabled(): void
    {
        $r = $this->makeLegacyStore();
        PlatformSettings::set('storefront_wati_widget', '0');
        config(['settings.storefront_wati_widget' => 0]); // provider ran before the Option existed

        $this->get(route('publicView', $r->slug))->assertOk()->assertDontSeeHtml(self::WATI);
    }

    public function test_wati_widget_is_absent_when_storefront_csp_is_enforced(): void
    {
        $r = $this->makeLegacyStore();
        config([
            'settings.storefront_wati_widget' => 1,
            'csp.enforce' => true,
        ]);

        $this->get(route('publicView', $r->slug))->assertOk()->assertDontSeeHtml(self::WATI);
    }

    public function test_enforced_csp_disables_storefront_etag_html_reuse(): void
    {
        $r = $this->makeLegacyStore();
        config([
            'csp.enforce' => true,
            'perf.menu_etag' => true,
        ]);

        $response = $this->get(route('publicView', $r->slug));

        $response->assertOk();
        // the visitor's own browser may revalidate (ETag / 304), but no shared cache may reuse nonce-bearing HTML
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString(
            'public',
            (string) $response->headers->get('Cache-Control'),
            'Nonce-bearing HTML must not be shared through the storefront ETag cache.'
        );
    }
}
