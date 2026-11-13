<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A2 — the menu hook in StoreController::publicView. Every assertion goes
 * HTTP-through-controller (`$this->get(route('publicView', …))`) per the hard
 * rule; a blade-direct render would miss the controller contract entirely.
 *
 * The hook is a single line ABOVE the biolink-first interstitial with an inline
 * `$store->user->status == 1` check (§3.4). These tests pin every load-bearing
 * property of that exact placement: it fires on an active store, bypasses for
 * the owner/support, still fires on an expired plan, goes DARK on a banned store
 * (H1), and returns before the ETag and scan-counter blocks below it.
 */
class ExternalRedirectMenuHookTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET_TPL = 'https://legacy.example.com/{slug}{query}';

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

    public function test_enabled_redirects_302_with_query_forwarding_and_no_store(): void
    {
        [, $store] = $this->makeStore();
        $this->enableRedirect($store);

        $response = $this->get(route('publicView', $store->slug).'?table=5');

        $response->assertStatus(302);
        $response->assertHeader('Location', 'https://legacy.example.com/'.$store->slug.'?table=5');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_disabled_renders_menu_and_does_not_redirect(): void
    {
        [, $store] = $this->makeStore();
        // No redirect options set → normal menu render.

        $response = $this->get(route('publicView', $store->slug));

        $this->assertLessThan(400, $response->status());
        $this->assertNotSame(302, $response->status());
    }

    public function test_owner_preview_bypasses_redirect(): void
    {
        [$owner, $store] = $this->makeStore();
        $this->enableRedirect($store);

        $response = $this->actingAs($owner)->get(route('publicView', $store->slug));

        $this->assertNotSame(302, $response->status());
        $this->assertLessThan(400, $response->status());
    }

    public function test_a_different_signed_in_owner_is_still_redirected(): void
    {
        [, $store] = $this->makeStore();
        $this->enableRedirect($store);
        $other = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $response = $this->actingAs($other)->get(route('publicView', $store->slug));

        $response->assertStatus(302);
        $response->assertHeader('Location', 'https://legacy.example.com/'.$store->slug);
    }

    public function test_redirect_zero_param_bypasses(): void
    {
        [, $store] = $this->makeStore();
        $this->enableRedirect($store);

        $response = $this->get(route('publicView', $store->slug).'?redirect=0');

        $this->assertNotSame(302, $response->status());
        $this->assertLessThan(400, $response->status());
    }

    public function test_expired_plan_store_still_redirects(): void
    {
        // Plan disabled (status 0) but the account is still active (status 1) —
        // the hook sits above the plan check, so an opted-out store keeps working.
        [, $store] = $this->makeStore(planStatus: 0);
        $this->enableRedirect($store);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertStatus(302);
        $response->assertHeader('Location', 'https://legacy.example.com/'.$store->slug);
    }

    public function test_banned_store_is_not_redirected(): void
    {
        // H1 abuse guard: a superadmin-banned store (account status 0) must go
        // dark, NOT keep pointing traffic at an arbitrary URL. A naive
        // before-status hook placement would 302 here — this is the discriminator.
        [$owner, $store] = $this->makeStore();
        $this->enableRedirect($store);
        $owner->status = 0;
        $owner->save();

        $response = $this->get(route('publicView', $store->slug));

        $location = (string) $response->headers->get('Location');
        $this->assertStringNotContainsString('legacy.example.com', $location);
        $this->assertNotSame(302, $response->status());
    }

    public function test_old_url_route_also_redirects(): void
    {
        [, $store] = $this->makeStore();
        $this->enableRedirect($store);

        // The legacy `restaurant/{slug}` route hits the same publicView method.
        $response = $this->get('/restaurant/'.$store->slug.'?table=5');

        $response->assertStatus(302);
        $response->assertHeader('Location', 'https://legacy.example.com/'.$store->slug.'?table=5');
    }

    public function test_numeric_id_url_also_redirects(): void
    {
        [, $store] = $this->makeStore();
        $this->enableRedirect($store);

        // The is_numeric($slug) path resolves the post by id — must redirect too.
        $response = $this->get(route('publicView', $store->id));

        $response->assertStatus(302);
        $response->assertHeader('Location', 'https://legacy.example.com/'.$store->slug);
    }

    public function test_redirect_fires_before_scan_counter_and_etag(): void
    {
        [, $store] = $this->makeStore();
        $this->enableRedirect($store);
        $qr = app_xor_encrypt($store->slug, 'qr-scan');

        $response = $this->get(route('publicView', $store->slug).'?qr-id='.urlencode($qr));

        $response->assertStatus(302);
        // The hook returns above the counter (line ~1282) — no scan is recorded…
        $this->assertSame(0, StoreView::where('store_id', $store->id)->count());
        // …and above the ETag/304 block — a 302 carries no validator.
        $this->assertNull($response->headers->get('ETag'));
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function makeStore(int $planStatus = 1): array
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
            'status' => $planStatus,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        StoreOption::updateStoreOption($store->id, 'store_template', 'juwri');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        return [$owner, $store];
    }

    private function enableRedirect(Store $store): void
    {
        StoreOption::updateStoreOption($store->id, 'external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'external_redirect_url', self::TARGET_TPL);
    }
}
