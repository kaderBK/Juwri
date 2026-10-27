<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Verifies Aurora (public menu template) renders correctly for diners.
 * Nova is the owner dashboard; Aurora is the diner-facing template.
 */
class AuroraPublicViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // Share $settings globally — Aurora template uses it for "Powered by {site_title}"
        // and Nova dashboard views use it for site branding.
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
                // Plan-gate flags used by store_templates/layout.blade.php
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

    private function setTemplate(Store $store, string $template): void
    {
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
    }

    /** Aurora template renders 200 for a valid slug */
    public function test_aurora_renders_for_public_view(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($store, 'aurora');

        // Add a menu item so the view has something to show
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));
        $response->assertOk();
        // Aurora template outputs its JS bundle reference and the menu item name
        $response->assertSeeHtml('Burger');
        $response->assertSeeHtml('aurora'); // aurora CSS/JS asset path
    }

    /** F7: the storefront viewport allows pinch-zoom (WCAG 1.4.4). */
    public function test_viewport_allows_pinch_zoom(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($store, 'aurora');

        $response = $this->get(route('publicView', $store->slug));
        $response->assertOk();
        $response->assertSeeHtml('name="viewport"');
        $response->assertDontSeeHtml('user-scalable=0');
        $response->assertDontSeeHtml('maximum-scale=1');
    }

    /** F10: the LCP preload points at the cover (fetchpriority=high), not the logo. */
    public function test_preloads_cover_image_as_lcp(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();
        $store->update(['cover_image' => 'hero-cover.webp']);
        $this->setTemplate($store, 'orbit');

        $response = $this->get(route('publicView', $store->slug));
        $response->assertOk();
        $response->assertSeeHtml('rel="preload" as="image" fetchpriority="high"');
        $response->assertSeeHtml('store/cover/');
    }

    /** F10: a store with only the default cover emits no image preload. */
    public function test_no_image_preload_without_a_cover(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant(); // cover_image = default.png
        $this->setTemplate($store, 'orbit');

        $response = $this->get(route('publicView', $store->slug));
        $response->assertOk();
        $response->assertDontSeeHtml('rel="preload" as="image"');
    }

    /** Unknown slug returns 404 */
    public function test_unknown_slug_returns_404(): void
    {
        $this->get(route('publicView', 'does-not-exist'))->assertNotFound();
    }

    /** Inactive owner hides restaurant (returns 404 path) */
    public function test_inactive_owner_hides_restaurant(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($store, 'aurora');

        // Deactivate owner
        $store->user->update(['status' => 0]);

        $this->get(route('publicView', $store->slug))->assertNotFound();
    }

    /** QR scan with valid qr-id logs StoreView and redirects to plain slug URL */
    public function test_qr_scan_logs_view_and_redirects(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($store, 'aurora');

        $qrEncoded = app_xor_encrypt($store->slug, 'qr-scan');

        // urlencode: the xor/base64 token can contain +, / or = which must be
        // percent-encoded in a query string (a raw '+' would decode to a space,
        // making the token fail to round-trip). This made the test flaky
        // depending on the random slug.
        $response = $this->get(route('publicView', $store->slug).'?qr-id='.urlencode($qrEncoded));

        $response->assertRedirect(route('publicView', $store->slug));
        $this->assertDatabaseHas('store_page_views', ['store_id' => $store->id]);
    }

    /** Nova dashboard (owner) returns 200 with dashboard content */
    public function test_nova_dashboard_loads_for_owner(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();
        // Nova dashboard renders; confirm it's actually the dashboard HTML not an error page
        $response->assertSeeHtml($store->title);
    }
}
