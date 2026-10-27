<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\GoogleOrdering;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\GoogleOrdering\GoogleOrderingService;
use Juwri\Modules\Storefront\Services\GoogleOrdering\OrderLinks;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;
use Tests\TestCase;

/**
 * P5-20 Google Ordering Redirect — the action-link builder and the public redirect route.
 *
 * `OrderLinks::url($store, <core type>)` is the stable HTTPS deep link into the store's own ordering page
 * (reseller / custom-domain / locale aware); the route answers 302 into the storefront with `order_type`.
 */
class GoogleOrderingLinksTest extends TestCase
{
    use RefreshDatabase;
    use GoogleOrderingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, EnsureStaffDashboardPermission::class]);
        $this->makeFeedDir();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->goDir);
        parent::tearDown();
    }

    private function links(): OrderLinks
    {
        return app(OrderLinks::class);
    }

    private function eligibleFoodStore(array $switches = []): Store
    {
        $store = $this->foodStore('links-'.Str::lower(Str::random(6)), 'Links', $switches);
        $this->googleOn($store);
        $this->geocodedBranch($store);

        return $store;
    }

    public function test_url_for_every_offered_type_is_https_order_link_with_utm(): void
    {
        config(['settings.include_language_code' => false]);
        $store = $this->eligibleFoodStore();
        $offered = $this->offeredTypes($store);
        $this->assertNotSame([], $offered, 'the fixture store offers types');

        foreach ($offered as $core) {
            $kit = OrderTypeKeys::toKit($core);
            $this->assertNotNull($kit, "a kit key for core type {$core}");

            $url = $this->links()->url($store, $core);
            $this->assertNotNull($url, "{$core} is offered => a URL");
            $this->assertStringStartsWith('https://', $url);
            $this->assertStringContainsString('/order/'.$kit, $url);
            $this->assertStringContainsString('utm_source=google', $url);
            $this->assertStringContainsString('utm_medium=ordering_redirect', $url);
            $this->assertStringContainsString($store->slug, $url);
        }
    }

    public function test_locale_prefix_is_added_when_asked(): void
    {
        config(['settings.include_language_code' => true]);
        $this->seedActiveLanguages();
        $store = $this->eligibleFoodStore();
        StoreSettings::setMany((int) $store->id, ['menu_languages' => ['en', 'ar']]);

        $this->assertContains('ar', $this->links()->locales($store));

        $url = $this->links()->url($store, 'takeaway', 'ar');
        $this->assertNotNull($url);
        $this->assertStringContainsString('/ar/', $url, 'the locale prefix is present');
        $this->assertStringContainsString('/order/takeaway', $url);
    }

    public function test_locales_is_one_empty_code_when_the_prefix_is_disabled(): void
    {
        config(['settings.include_language_code' => false]);
        $store = $this->eligibleFoodStore();

        $this->assertSame([''], $this->links()->locales($store));
    }

    public function test_custom_domain_store_gets_its_own_host(): void
    {
        config(['settings.include_language_code' => false]);
        $store = $this->eligibleFoodStore();
        StoreDomain::create([
            'store_id' => $store->id, 'type' => 'custom', 'host' => 'menu.golinks.test',
            'verified_at' => now(), 'verification_token' => Str::random(40),
        ]);

        $url = $this->links()->url($store, 'takeaway');

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://menu.golinks.test/order/takeaway', $url);
    }

    public function test_null_for_a_type_the_store_does_not_offer(): void
    {
        config(['settings.include_language_code' => false]);
        $store = $this->eligibleFoodStore([
            'restaurant_takeaway_order' => true,
            'restaurant_delivery_order' => false,
            'restaurant_on_table_order' => false,
        ]);

        $this->assertNotNull($this->links()->url($store, 'takeaway'));
        $this->assertNull($this->links()->url($store, 'delivery'), 'delivery is switched off');
        $this->assertNull($this->links()->url($store, 'not_a_real_type'));
    }

    public function test_service_gate_reads_config_and_the_owner_switch(): void
    {
        config(['services.google_ordering.enabled' => false]);
        $this->assertFalse(GoogleOrderingService::enabled());

        config(['services.google_ordering.enabled' => true, 'services.google_ordering.driver' => 'fake']);
        $this->assertTrue(GoogleOrderingService::enabled());
        $this->assertSame('fake', GoogleOrderingService::driver());

        $store = $this->foodStore('gate-'.Str::lower(Str::random(6)), 'Gate');
        $this->assertFalse(GoogleOrderingService::storeEnabled((int) $store->id), 'default off');
        $this->googleOn($store);
        $this->assertTrue(GoogleOrderingService::storeEnabled((int) $store->id));
    }

    public function test_route_path_form_redirects_with_order_type_for_an_offered_type(): void
    {
        config(['settings.include_language_code' => false]);
        $store = $this->eligibleFoodStore();

        $this->assertSame('/'.$store->slug.'/order/takeaway',
            route('storefront.googleOrdering.link', ['slug' => $store->slug, 'type' => 'takeaway'], false));

        $res = $this->get('/'.$store->slug.'/order/takeaway?utm_source=google&utm_medium=ordering_redirect');
        $res->assertStatus(302);

        $query = [];
        parse_str((string) parse_url((string) $res->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('takeaway', $query['order_type'] ?? null);
        $this->assertSame('google', $query['utm_source'] ?? null, 'the incoming utm params are forwarded');
        $this->assertSame('ordering_redirect', $query['utm_medium'] ?? null);
    }

    public function test_route_path_form_redirects_without_order_type_for_an_unknown_type(): void
    {
        config(['settings.include_language_code' => false]);
        $store = $this->eligibleFoodStore();

        $res = $this->get('/'.$store->slug.'/order/teleport');
        $res->assertStatus(302);

        $query = [];
        parse_str((string) parse_url((string) $res->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('order_type', $query);
    }

    public function test_route_path_form_unknown_store_is_404(): void
    {
        $this->get('/no-such-store-xyz/order/takeaway')->assertNotFound();
    }

    public function test_route_host_form_on_the_stores_own_host(): void
    {
        config(['settings.include_language_code' => false]);
        $store = $this->eligibleFoodStore();
        StoreDomain::create([
            'store_id' => $store->id, 'type' => 'custom', 'host' => 'menu.gohost.test',
            'verified_at' => now(), 'verification_token' => Str::random(40),
        ]);

        $res = $this->get('https://menu.gohost.test/order/takeaway');
        $res->assertStatus(302);

        $location = (string) $res->headers->get('Location');
        $this->assertSame('menu.gohost.test', parse_url($location, PHP_URL_HOST));
        $query = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('takeaway', $query['order_type'] ?? null);
    }

    public function test_url_carries_the_branch_public_id_only_when_a_branch_is_passed(): void
    {
        config(['settings.include_language_code' => false]);
        $store = $this->eligibleFoodStore();
        $branch = Branch::query()->where('store_id', $store->id)->firstOrFail();

        $with = $this->links()->url($store, 'takeaway', null, $branch);
        $without = $this->links()->url($store, 'takeaway');

        $this->assertNotNull($with);
        $this->assertNotNull($without);
        $this->assertStringContainsString('branch='.$branch->public_id, $with);
        $this->assertStringContainsString('utm_source=google', $with);
        $this->assertStringNotContainsString('branch=', $without);
        $this->assertLessThan(strpos($with, 'utm_source='), strpos($with, 'branch='), 'branch precedes the utm params');
    }

    public function test_route_keeps_a_valid_branch_param(): void
    {
        config(['settings.include_language_code' => false]);
        $store = $this->eligibleFoodStore();
        $branch = Branch::query()->where('store_id', $store->id)->firstOrFail();

        $res = $this->get('/'.$store->slug.'/order/takeaway?branch='.$branch->public_id);
        $res->assertStatus(302);

        $query = [];
        parse_str((string) parse_url((string) $res->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame($branch->public_id, $query['branch'] ?? null);
    }

    public function test_route_drops_another_stores_branch_param(): void
    {
        config(['settings.include_language_code' => false]);
        $store = $this->eligibleFoodStore();
        $other = $this->foodStore('other-'.Str::lower(Str::random(6)), 'Other');
        $otherBranch = $this->geocodedBranch($other);

        $res = $this->get('/'.$store->slug.'/order/takeaway?branch='.$otherBranch->public_id);
        $res->assertStatus(302);

        $query = [];
        parse_str((string) parse_url((string) $res->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('branch', $query);
    }

    public function test_route_drops_an_inactive_or_garbage_branch_param(): void
    {
        config(['settings.include_language_code' => false]);
        $store = $this->eligibleFoodStore();
        $inactive = Branch::create([
            'store_id' => $store->id, 'name' => 'Closed', 'active' => 0,
            'accepting_orders' => 0, 'timezone' => 'Asia/Riyadh',
        ]);

        foreach ([$inactive->public_id, 'not-a-uuid'] as $candidate) {
            $res = $this->get('/'.$store->slug.'/order/takeaway?branch='.$candidate);
            $res->assertStatus(302);

            $query = [];
            parse_str((string) parse_url((string) $res->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->assertArrayNotHasKey('branch', $query);
        }

        $this->assertNotNull($this->links()->url($store, 'takeaway'), 'the store still offers takeaway');
    }
}
