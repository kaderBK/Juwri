<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\SitemapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Tests\Support\AisleStore;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-307: a store on its own domain serves its own robots.txt and sitemap.xml; P6-306: the platform lists the primary address. */
class P6307StoreHostSitemapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        Cache::flush();
    }

    public function test_custom_domain_sitemap_lists_only_that_store(): void
    {
        $shop = AisleStore::make();
        StoreDomain::create(['store_id' => $shop->store->id, 'type' => 'custom', 'host' => 'shop.dune.test', 'is_primary' => true, 'verified_at' => now(), 'verification_token' => 't']);

        $xml = $this->get('https://shop.dune.test/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>https://shop.dune.test</loc>', $xml);
        $this->assertStringContainsString('<loc>https://shop.dune.test?item='.ApiFixtures::SHIRT.'</loc>', $xml);
        $this->assertStringNotContainsString('sitemap-marketing', $xml);
        $this->assertStringNotContainsString('/dune-and-co-demo', $xml);
        preg_match_all('#<url>\s*<loc>([^<]+)</loc>#', $xml, $m);
        $this->assertGreaterThanOrEqual(2, count($m[1]));
        foreach ($m[1] as $loc) {
            $this->assertStringStartsWith('https://shop.dune.test', $loc, 'every URL is on the store host');
        }
    }

    public function test_custom_domain_robots_points_at_its_own_sitemap(): void
    {
        config(['seo.noindex' => false]);
        $this->app->detectEnvironment(fn () => 'production');
        $shop = AisleStore::make();
        StoreDomain::create(['store_id' => $shop->store->id, 'type' => 'custom', 'host' => 'shop.dune.test', 'is_primary' => true, 'verified_at' => now(), 'verification_token' => 't']);

        $this->get('https://shop.dune.test/robots.txt')->assertOk()->assertSee('Sitemap: https://shop.dune.test/sitemap.xml', false);
    }

    public function test_platform_host_sitemap_is_unchanged(): void
    {
        AisleStore::make();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('sitemap-marketing.xml', $xml);
        $this->assertStringContainsString('sitemap-restaurants', $xml);
    }

    public function test_platform_restaurant_sitemap_lists_the_primary_domain(): void
    {
        $shop = AisleStore::make();
        StoreDomain::create(['store_id' => $shop->store->id, 'type' => 'custom', 'host' => 'shop.dune.test', 'is_primary' => true, 'verified_at' => now(), 'verification_token' => 't']);

        $xml = $this->get('/sitemap-restaurants.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>https://shop.dune.test</loc>', $xml);
        $this->assertStringNotContainsString('<loc>'.route('publicView', $shop->store->slug).'</loc>', $xml);
    }

    public function test_restaurant_sitemap_query_count_does_not_grow_with_stores(): void
    {
        AisleStore::make();
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            SitemapService::restaurantEntries();

            return count(DB::getQueryLog());
        };
        $one = $count();
        for ($i = 0; $i < 49; $i++) {
            $this->extraStore();
        }
        $fifty = $count();

        $this->assertSame(50, count(SitemapService::restaurantEntries()));
        $this->assertLessThanOrEqual($one + 3, $fifty, "queries: 1 store = $one, 50 stores = $fifty");
    }

    public function test_restaurant_sitemap_splits_past_the_file_limit(): void
    {
        config(['seo.sitemap_restaurants_per_file' => 1]);
        AisleStore::make();
        $this->extraStore();
        $this->extraStore();

        $this->assertCount(3, SitemapService::restaurantEntries(null));
        $this->assertCount(1, SitemapService::restaurantEntries(2));
        $index = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('sitemap-restaurants-3.xml', $index);
        $this->get('/sitemap-restaurants-3.xml')->assertOk();
        $this->get('/sitemap-restaurants-4.xml')->assertNotFound();
    }

    public function test_batch_plans_equal_the_per_owner_plan(): void
    {
        AisleStore::make();
        $this->extraStore();
        $free = User::factory()->create(['group_id' => 'free', 'status' => 1]);
        $users = User::query()->get();
        $batch = app(PlanCatalog::class)->plansFor($users);

        $this->assertCount($users->count(), $batch);
        foreach ($users as $user) {
            $single = app(PlanCatalog::class)->planFor($user->fresh());
            $this->assertEquals($single->id ?? null, $batch[$user->id]->id ?? null);
            $this->assertEquals($single->status ?? null, $batch[$user->id]->status ?? null);
        }
    }

    private function extraStore(): void
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'scan_limit' => 99, 'menu_limit' => 99], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $t = 'R'.Str::random(6);
        $s = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        Menu::create(['store_id' => $s->id, 'name' => 'I', 'price' => 5, 'active' => 1]);
    }
}
