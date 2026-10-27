<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pharmacy;

use App\Services\SitemapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\PharmacyMarket;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-48 — public surfaces outside the marketplace scope must apply the same
 * `store.public-listing` fence as Store::mkApproved(): an unpublished pharmacy
 * is absent from the store sitemap and the landing-page logo strip.
 */
class PharmacyPublicListingLeaksTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(string $type): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'Store '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'store_type' => $type,
            'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#1f6feb',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'logo.png', 'cover_image' => 'logo.png',
            'address' => '1 St', 'phone' => '1234',
        ]);
        Menu::create(['store_id' => $store->id, 'name' => 'Item', 'price' => 3, 'active' => 1]);

        return $store;
    }

    public function test_sitemap_omits_an_unpublished_pharmacy(): void
    {
        $cafe = $this->makeStore('restaurant');
        $pharmacy = $this->makeStore('pharmacy');

        $locs = array_column(SitemapService::restaurantEntries(), 'loc');
        $this->assertContains(route('publicView', $cafe->slug), $locs);
        $this->assertNotContains(route('publicView', $pharmacy->slug), $locs);
    }

    public function test_sitemap_lists_a_published_pharmacy(): void
    {
        $pharmacy = $this->makeStore('pharmacy');
        PharmacyMarket::open($pharmacy);

        $this->assertContains(route('publicView', $pharmacy->slug), array_column(SitemapService::restaurantEntries(), 'loc'));
    }

    public function test_landing_logo_strip_omits_an_unpublished_pharmacy(): void
    {
        Cache::flush();
        $cafe = $this->makeStore('restaurant');
        $pharmacy = $this->makeStore('pharmacy');

        $ids = marquee_stores()->pluck('id')->all();
        $this->assertContains($cafe->id, $ids);
        $this->assertNotContains($pharmacy->id, $ids);
    }
}
