<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Http\Middleware\CacheStorefrontResponse;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Full-page storefront cache (CacheStorefrontResponse) contract.
 *
 * The middleware is a no-op under the test runner by design (the shared `array`
 * cache store would bleed one test's page into another), so we bind a subclass
 * that lifts that single guard and exercise the real MISS→HIT path plus every
 * bypass that must NEVER serve a shared page: signed-in diner, ?nocache, dine-in
 * table param, a store outside the canary allowlist, and token invalidation.
 */
class StorefrontFullPageCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // Production hands views an array_to_object() stdClass, not an anonymous
        // double — build the layout context through the same expression.
        $this->shareLayoutGlobals();

        // Lift ONLY the test-runner guard so the cache path runs; every other
        // bypass (config flag, canary, auth, params) stays live and under test.
        app()->instance(CacheStorefrontResponse::class, new class extends CacheStorefrontResponse
        {
            protected function disabledForTesting(): bool
            {
                return false;
            }
        });
    }

    public function test_second_guest_hit_is_served_from_cache(): void
    {
        $store = $this->makeRenderableStore();
        $this->enableCacheFor($store->slug);

        $first = $this->get(route('publicView', $store->slug));
        $first->assertOk();
        $this->assertSame('MISS', $first->headers->get('X-Storefront-Cache'), 'first guest view should populate the cache');

        $second = $this->get(route('publicView', $store->slug));
        $second->assertOk();
        $this->assertSame('HIT', $second->headers->get('X-Storefront-Cache'), 'second identical guest view should be served from cache');

        // The stored body never keeps a real token, and the served HIT carries a
        // live one — the sentinel must never leak to a diner.
        $body = $second->getContent();
        $this->assertStringNotContainsString('@@SF_CSRF@@', $body, 'CSRF sentinel leaked into the served page');
        $this->assertStringContainsString(csrf_token(), $body, 'HIT should re-inject the current CSRF token');
    }

    public function test_signed_in_diner_is_never_served_the_shared_page(): void
    {
        $store = $this->makeRenderableStore();
        $this->enableCacheFor($store->slug);

        // Prime the guest cache.
        $this->get(route('publicView', $store->slug))->assertOk();

        // A logged-in customer sees their own name/addresses inline — must bypass.
        $customer = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $resp = $this->actingAs($customer, 'customer')->get(route('publicView', $store->slug));
        $this->assertNotSame('HIT', $resp->headers->get('X-Storefront-Cache'), 'authenticated diner must not receive the shared cached page');
    }

    public function test_bypass_params_are_never_cached(): void
    {
        $store = $this->makeRenderableStore();
        $this->enableCacheFor($store->slug);

        foreach (['nocache' => '1', 'table' => '5', 'preview_template' => 'crave', 'fragment' => 'item', 'item' => '0198a000-0000-7000-8000-000000000001', 'category' => 'cat_x', 'offset' => '60', 'q' => 'tea', 'sort' => 'price_asc', 'cursor' => 'abc'] as $param => $value) {
            $resp = $this->get(route('publicView', $store->slug).'?'.$param.'='.$value);
            $this->assertNull($resp->headers->get('X-Storefront-Cache'), "?{$param} must bypass the full-page cache");
        }
    }

    public function test_campaign_tags_do_not_split_or_bypass_the_cache(): void
    {
        $store = $this->makeRenderableStore();
        $this->enableCacheFor($store->slug);

        $this->get(route('publicView', $store->slug).'?utm_source=news&fbclid=1')->assertOk();
        $this->assertSame('HIT', $this->get(route('publicView', $store->slug))->headers->get('X-Storefront-Cache'));
    }

    /** owner decision 2026-10-08: off for everyone; the account owner can trial it for one store; staff and team members cannot */
    public function test_the_page_cache_is_off_by_default_and_the_owner_can_trial_it_for_one_store(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $store = $this->makeRenderableStore();
        $other = $this->makeRenderableStore();
        $this->assertFalse((bool) config('perf.storefront_fullpage_cache'), 'master switch off by default');
        $this->assertSame(300, (int) config('perf.storefront_fullpage_cache_trial_ttl'));

        $this->assertNull($this->get(route('publicView', $store->slug))->headers->get('X-Storefront-Cache'), 'nothing is cached by default');

        $owner = User::find($store->user_id);
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->post(route('backup.fullpage.trial'), ['enabled' => 1])->assertRedirect();
        $this->assertTrue(StoreSettings::bool((int) $store->id, 'storefront_fullpage_cache_trial', false));
        Auth::forgetGuards();

        $this->get(route('publicView', $store->slug)); // the settings page's flash message is consumed by this first view (a flash is never cached)
        $this->assertSame('MISS', $this->get(route('publicView', $store->slug))->headers->get('X-Storefront-Cache'));
        $this->assertSame('HIT', $this->get(route('publicView', $store->slug))->headers->get('X-Storefront-Cache'));
        $this->assertNull($this->get(route('publicView', $other->slug))->headers->get('X-Storefront-Cache'), 'another store is not affected');

        $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])->post(route('backup.fullpage.trial'), ['enabled' => 0])->assertRedirect();
        Auth::forgetGuards();
        $this->get(route('publicView', $store->slug));
        $this->assertNull($this->get(route('publicView', $store->slug))->headers->get('X-Storefront-Cache'), 'off again');
    }

    public function test_a_team_member_or_staff_session_cannot_switch_the_page_cache(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $store = $this->makeRenderableStore();
        $owner = User::find($store->user_id);
        $member = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'account_id' => $owner->account_id]);

        $this->actingAs($member)->withSession(['dashboard_store_id' => $store->id])->post(route('backup.fullpage.trial'), ['enabled' => 1])->assertForbidden();
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id, 'staff_session_id' => 1])->post(route('backup.fullpage.trial'), ['enabled' => 1])->assertForbidden();
        $this->assertFalse(StoreSettings::bool((int) $store->id, 'storefront_fullpage_cache_trial', false));
    }

    public function test_store_outside_canary_is_not_cached(): void
    {
        $store = $this->makeRenderableStore();
        // Canary names a DIFFERENT slug — this store is not in the allowlist.
        config([
            'perf.storefront_fullpage_cache' => true,
            'perf.storefront_fullpage_cache_canary' => 'some-other-store',
        ]);

        $resp = $this->get(route('publicView', $store->slug));
        $this->assertNull($resp->headers->get('X-Storefront-Cache'), 'a store outside the canary allowlist must not be cached');
    }

    public function test_token_bump_invalidates_the_cached_page(): void
    {
        $store = $this->makeRenderableStore();
        $this->enableCacheFor($store->slug);

        $this->get(route('publicView', $store->slug))->assertOk();
        $this->assertSame('HIT', $this->get(route('publicView', $store->slug))->headers->get('X-Storefront-Cache'));

        // Any menu/settings/theme write bumps the store's token; simulate that.
        StorefrontMenuCache::bust($store->id);

        $after = $this->get(route('publicView', $store->slug));
        $this->assertSame('MISS', $after->headers->get('X-Storefront-Cache'), 'a token bump must invalidate the cached page');
    }

    private function enableCacheFor(string $slug): void
    {
        config([
            'perf.storefront_fullpage_cache' => true,
            'perf.storefront_fullpage_cache_canary' => $slug,
        ]);
    }

    private function makeRenderableStore(): Store
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
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        StoreOption::updateStoreOption($store->id, 'store_template', 'juwri');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        return $store;
    }
}
