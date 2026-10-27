<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

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
 * A6 — the owner-selectable interstitial ("menu moved" page). When
 * external_redirect_interstitial = '1' the resolver returns a 200 page with a
 * meta-refresh + button instead of an instant 302. All assertions go
 * HTTP-through-controller; the CSP suite is run as a regression alongside.
 */
class ExternalRedirectInterstitialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    public function test_interstitial_on_returns_200_with_meta_refresh_button_and_no_store(): void
    {
        [, $store] = $this->makeStore();
        $this->enableRedirect($store);
        StoreOption::updateStoreOption($store->id, 'external_redirect_interstitial', '1');

        $response = $this->get(route('publicView', $store->slug).'?table=5');
        $target = 'https://legacy.example.com/'.$store->slug.'?table=5';

        $response->assertStatus(200);
        // Auto-redirect is a CSP-safe meta-refresh (no JS)…
        $response->assertSeeHtml('http-equiv="refresh"');
        $response->assertSeeHtml('content="2;url='.$target.'"');
        // …plus a visible fallback button to the same target.
        $response->assertSeeHtml('href="'.$target.'"');
        $response->assertSeeHtml(___('This menu has moved'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // No inline <style>/<script> may leak into the CSP-scoped page.
        $this->assertStringNotContainsString('<style', $response->getContent());
        $this->assertStringNotContainsString('<script', $response->getContent());
    }

    public function test_interstitial_off_is_an_instant_302(): void
    {
        [, $store] = $this->makeStore();
        $this->enableRedirect($store);
        StoreOption::updateStoreOption($store->id, 'external_redirect_interstitial', '0');

        $this->get(route('publicView', $store->slug))->assertStatus(302);
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function makeStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4),
            'description' => 'T',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        return [$owner, $store];
    }

    private function enableRedirect(Store $store): void
    {
        StoreOption::updateStoreOption($store->id, 'external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'external_redirect_url', 'https://legacy.example.com/{slug}{query}');
    }
}
