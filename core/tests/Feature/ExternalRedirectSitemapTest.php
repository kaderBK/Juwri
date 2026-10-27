<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\StoreOption;
use App\Services\SitemapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A4 — a store that has opted out (external_redirect_enabled = '1') must be
 * excluded from SitemapService::restaurantEntries() so crawlers never chase the
 * 302. Calls the service directly (the controller's Cache::remember wrapper is
 * bypassed — the documented cache lag is acceptable, forgotten on save by B/C/D).
 */
class ExternalRedirectSitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirected_store_is_excluded_but_normal_store_is_present(): void
    {
        [, $normal] = $this->makeActiveStore();
        [, $redirected] = $this->makeActiveStore();
        StoreOption::updateStoreOption($redirected->id, 'external_redirect_enabled', '1');
        StoreOption::updateStoreOption($redirected->id, 'external_redirect_url', 'https://legacy.example.com/{slug}');

        $locs = array_column(SitemapService::restaurantEntries(), 'loc');

        $this->assertContains(route('publicView', $normal->slug), $locs, 'A normal active store must stay in the sitemap.');
        $this->assertNotContains(route('publicView', $redirected->slug), $locs, 'A redirected store must be excluded from the sitemap.');
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function makeActiveStore(): array
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
        // A store must have a visible menu item to be sitemap-indexable (empty
        // stores are Soft 404s) — give this "active store" one.
        Menu::create(['store_id' => $store->id, 'name' => 'Item '.Str::random(4), 'price' => 5, 'active' => 1]);

        return [$owner, $store];
    }
}
