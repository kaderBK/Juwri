<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Api;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\Entitlements;
use App\Services\Storefront\StorefrontMenuCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\StorefrontApi\ApiContract;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-06b phase A — the gates in front of the headless API: the plan feature (403 `storefront_api_not_enabled` with
 * `details.feature`, API-5), servability (404), the `storefront-write` limiter (429 + Retry-After in the ApiError
 * envelope), the read caching (ETag / 304), the store / catalog / item reads against the contract, and the dev-only
 * contract validator (Q6) staying out of every production install.
 */
class StorefrontApiGatesTest extends TestCase
{
    use RefreshDatabase;

    private ApiFixtures $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        $this->travelTo(Carbon::parse('2026-10-02 18:40:00', 'UTC'));
        $this->fx = new ApiFixtures;
    }

    /** P4-16: a food catalogue above perf.storefront_catalog_auto_page items comes in pages with next_cursor; below it nothing changes */
    public function test_a_very_large_food_catalogue_is_paged_without_the_client_asking(): void
    {
        $this->fx->saffron();
        $h = ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001', 'Accept-Language' => 'en'];

        $whole = $this->getJson('/api/v1/storefront/catalog', $h)->assertOk();
        $count = count($whole->json('catalog.items'));
        $this->assertGreaterThan(2, $count);
        $this->assertNull($whole->json('next_cursor'));

        // 60 more items: above the (lowered) threshold the first 48 come with next_cursor, without per_page in the request
        $proto = Menu::query()->where('public_id', ApiFixtures::TAWOOK)->firstOrFail();
        for ($i = 0; $i < 60; $i++) {
            $copy = $proto->replicate(['public_id']);
            $copy->name = 'Extra '.$i;
            $copy->save();
        }
        StorefrontMenuCache::bust((int) $proto->store_id);
        $count += 60;
        config(['perf.storefront_catalog_auto_page' => 50]);
        $auto = $this->getJson('/api/v1/storefront/catalog', $h)->assertOk();
        ApiContract::assertResponse('getCatalog', 'GET', '/api/v1/storefront/catalog', $auto);
        $this->assertCount(48, $auto->json('catalog.items'));
        $this->assertSame($count, $auto->json('catalog.total'));
        $this->assertNotNull($auto->json('next_cursor'));
        config(['perf.storefront_catalog_auto_page' => 300]);
        $this->assertNull($this->getJson('/api/v1/storefront/catalog', $h)->json('next_cursor'), 'below the threshold: one page as before');

        // walk the pages: every item exactly once
        $seen = [];
        $url = '/api/v1/storefront/catalog?per_page=40';
        for ($i = 0; $i < 20 && $url; $i++) {
            $page = $this->getJson($url, $h)->assertOk();
            $seen = array_merge($seen, array_keys($page->json('catalog.items')));
            $cursor = $page->json('next_cursor');
            $url = $cursor ? '/api/v1/storefront/catalog?per_page=40&cursor='.urlencode($cursor) : null;
        }
        $this->assertCount($count, array_unique($seen));
        $this->getJson('/api/v1/storefront/catalog?per_page=101', $h)->assertStatus(422);
    }

    /** P6-24: a food catalogue answer (cards + search index) is cached whole: the same body, and an item edit shows at once */
    public function test_a_cached_food_catalogue_answer_is_identical_and_follows_item_edits(): void
    {
        $this->fx->saffron();
        $h = ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001', 'Accept-Language' => 'en'];
        config(['perf.storefront_catalog_cache_ttl' => 60]);
        Cache::flush();
        $url = '/api/v1/storefront/catalog?q=a&sort=price_desc&in_stock=true';

        $miss = $this->getJson($url, $h)->assertOk()->json();
        $hit = $this->getJson($url, $h)->assertOk()->json();
        $this->assertSame($miss, $hit, 'a cache hit answers what a miss answers (cards, order, filters)');
        $this->assertNotEmpty($miss['catalog']['items']);

        $first = Menu::query()->where('public_id', array_key_first($hit['catalog']['items']))->firstOrFail();
        $first->name = 'Renamed by the owner';
        $first->save();
        $after = $this->getJson('/api/v1/storefront/catalog', $h)->assertOk()->json('catalog.items');
        $this->assertSame('Renamed by the owner', $after[(string) $first->public_id]['name']);
    }

    public function test_store_catalog_and_item_reads_match_the_contract_and_cache(): void
    {
        $this->fx->saffron();
        $h = ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001', 'Accept-Language' => 'en'];

        $store = $this->getJson('/api/v1/storefront/store', $h)->assertOk();
        ApiContract::assertResponse('getStore', 'GET', '/api/v1/storefront/store', $store);
        $this->assertSame('Saffron House', $store->json('store.name'));
        $this->assertSame(['release_during_payment' => false], $store->json('holds'));

        $catalog = $this->getJson('/api/v1/storefront/catalog', $h)->assertOk();
        ApiContract::assertResponse('getCatalog', 'GET', '/api/v1/storefront/catalog', $catalog);
        $this->assertArrayHasKey(ApiFixtures::GRILL, $catalog->json('catalog.items'));
        $this->assertSame(8000, $catalog->json('catalog.items.'.ApiFixtures::GRILL.'.from_price.minor') ?? $catalog->json('catalog.items.'.ApiFixtures::GRILL.'.price.minor'));

        $narrow = $this->getJson('/api/v1/storefront/catalog?q=tawook', $h)->assertOk();
        $this->assertSame([ApiFixtures::TAWOOK], array_keys($narrow->json('catalog.items')));
        ApiContract::assertResponse('getCatalog', 'GET', '/api/v1/storefront/catalog', $narrow);

        $item = $this->getJson('/api/v1/storefront/items/'.ApiFixtures::GRILL, $h)->assertOk();
        ApiContract::assertResponse('getItem', 'GET', '/api/v1/storefront/items/'.ApiFixtures::GRILL, $item);
        $this->assertSame(['kind' => 'unit'], $item->json('sell'));
        $ids = array_merge(...array_map(fn ($g) => array_column($g['options'], 'id'), $item->json('item.option_groups')));
        $this->assertEqualsCanonicalizing([ApiFixtures::OPT_FOR_ONE, ApiFixtures::OPT_GARLIC, ApiFixtures::OPT_HUMMUS], $ids, 'option public ids (API-18)');
        foreach ($item->json('item.option_groups') as $group) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $group['id'], 'option group public ids (API-20)');
        }

        // 304 on the same ETag; a sold-out flip changes it
        $etag = (string) $item->headers->get('ETag');
        $this->getJson('/api/v1/storefront/items/'.ApiFixtures::GRILL, $h + ['If-None-Match' => $etag])->assertStatus(304);
        Menu::query()->where('public_id', ApiFixtures::GRILL)->update(['is_sold_out' => 1]);
        $after = $this->getJson('/api/v1/storefront/items/'.ApiFixtures::GRILL, $h + ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $after->headers->get('ETag'));
        $this->assertSame('out', $after->json('stock.state'));
    }

    public function test_stock_is_a_state_and_the_quantity_shows_only_when_low(): void
    {
        $this->fx->saffron();
        $h = ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001'];
        Menu::query()->where('public_id', ApiFixtures::TAWOOK)->update(['track_inventory' => true, 'stock_qty' => 20, 'low_stock_threshold' => 3]);
        $this->getJson('/api/v1/storefront/items/'.ApiFixtures::TAWOOK, $h)->assertJsonPath('stock.state', 'in_stock')->assertJsonPath('stock.qty', null);
        Menu::query()->where('public_id', ApiFixtures::TAWOOK)->update(['stock_qty' => 2]);
        $this->getJson('/api/v1/storefront/items/'.ApiFixtures::TAWOOK, $h)->assertJsonPath('stock.state', 'low')->assertJsonPath('stock.qty', 2);
        $this->getJson('/api/v1/storefront/items/'.ApiFixtures::GRILL, $h)->assertJsonPath('stock.state', 'untracked');
    }

    public function test_a_plan_without_the_storefront_api_answers_storefront_api_not_enabled(): void
    {
        $owner = $this->fx->owner(['storefront_api' => '0']);

        // The storefront API is a plan key: an edition that grants every plan key has nothing to withhold.
        if ((string) (Entitlements::planTrace($owner, 'storefront_api')['value'] ?? '') !== '0') {
            $this->markTestSkipped('This edition grants every plan key (Community): nothing to withhold.');
        }

        $this->fx->saffron($owner);
        foreach ([['GET', '/api/v1/storefront/store', 'getStore'], ['GET', '/api/v1/storefront/menu', 'legacyMenu']] as [$m, $path, $op]) {
            $r = $this->json($m, $path, [], ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001'])->assertForbidden()
                ->assertJsonPath('code', 'storefront_api_not_enabled')->assertJsonPath('details.feature', 'storefront_api');
            ApiContract::juwriRules($op, $r);
        }
    }

    public function test_a_store_that_is_not_served_answers_404(): void
    {
        $store = $this->fx->saffron();
        User::query()->whereKey($store->user_id)->update(['status' => 0]);
        // a suspended owner: the token gate refuses on the plan, or the servability gate on the store — never data
        $r = $this->getJson('/api/v1/storefront/catalog', ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001']);
        $this->assertContains($r->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('Mixed grill', (string) $r->getContent());
    }

    public function test_the_write_limiter_answers_429_with_retry_after_in_the_error_envelope(): void
    {
        $this->fx->saffron();
        config(['api.storefront_write_rate' => 2]);
        $body = ['order_type' => 'takeaway', 'lines' => [['item_id' => ApiFixtures::TAWOOK, 'qty' => 1]]];
        $h = ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001'];
        $this->postJson('/api/v1/storefront/quote', $body, $h)->assertOk();
        $this->postJson('/api/v1/storefront/quote', $body, $h)->assertOk();
        $r = $this->postJson('/api/v1/storefront/quote', $body, $h)->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->assertNotEmpty($r->headers->get('Retry-After'));
        ApiContract::assertResponse('quoteCart', 'POST', '/api/v1/storefront/quote', $r);
        // reads keep their own budget
        $this->getJson('/api/v1/storefront/store', $h)->assertOk();
    }

    public function test_the_account_ceiling_spans_every_token_of_the_account(): void
    {
        $owner = $this->fx->owner();
        $a = $this->fx->saffron($owner);
        $this->fx->token($a, 'sfx_second_token', ['menu:read']);
        config(['api.storefront_write_rate' => 100, 'tenancy.rate_limits.storefront_write_per_account' => 3]);
        $body = ['order_type' => 'takeaway', 'lines' => [['item_id' => ApiFixtures::TAWOOK, 'qty' => 1]]];
        foreach (['sfx_demo_saffron_read_0001', 'sfx_second_token', 'sfx_demo_saffron_read_0001'] as $token) {
            $this->postJson('/api/v1/storefront/quote', $body, ['X-Storefront-Token' => $token])->assertOk();
        }
        $this->postJson('/api/v1/storefront/quote', $body, ['X-Storefront-Token' => 'sfx_second_token'])->assertStatus(429);
    }

    public function test_the_otp_request_is_rate_limited_per_store_and_phone_with_retry_after(): void
    {
        $this->fx->sidra();
        $h = fn () => ['X-Storefront-Token' => 'sfx_demo_sidra_write_0001', 'Origin' => 'https://sidra-salon.example', 'Idempotency-Key' => (string) Str::uuid7()];
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/storefront/customer/otp/request', ['phone' => '+966551234567'], $h())->assertStatus(202);
        }
        $r = $this->postJson('/api/v1/storefront/customer/otp/request', ['phone' => '+966551234567'], $h())->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->assertGreaterThan(0, (int) $r->headers->get('Retry-After'));
        ApiContract::assertResponse('requestCustomerOtp', 'POST', '/api/v1/storefront/customer/otp/request', $r);

        // the same answer for a well-formed phone that has an account and one that has none (no enumeration)
        $other = $this->postJson('/api/v1/storefront/customer/otp/request', ['phone' => '+966559999999'], $h())->assertStatus(202);
        $this->assertSame(['message', 'expires_in_seconds'], array_keys($other->json()));
    }

    public function test_an_otp_retry_with_the_same_key_sends_no_second_code(): void
    {
        $store = $this->fx->sidra();
        $key = (string) Str::uuid7();
        $h = ['X-Storefront-Token' => 'sfx_demo_sidra_write_0001', 'Origin' => 'https://sidra-salon.example', 'Idempotency-Key' => $key];
        $first = $this->postJson('/api/v1/storefront/customer/otp/request', ['phone' => '+966551234567'], $h)->assertStatus(202);
        $again = $this->postJson('/api/v1/storefront/customer/otp/request', ['phone' => '+966551234567'], $h)->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json(), $again->json());
        $this->assertSame(1, RateLimiter::attempts('otp-req:store:'.$store->id.':+966551234567'), 'one code was requested');
        $this->postJson('/api/v1/storefront/customer/otp/request', ['phone' => '+966550000000'], $h)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
    }

    public function test_the_dev_only_validator_never_reaches_a_production_install(): void
    {
        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
        $json = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $prod = array_column($lock['packages'], 'name');
        $dev = [];
        foreach ($lock['packages-dev'] as $p) {
            $dev[$p['name']] = $p['license'] ?? [];
        }
        foreach (['studio-design/gesso' => 'MIT', 'opis/json-schema' => 'Apache-2.0', 'opis/string' => 'Apache-2.0', 'opis/uri' => 'Apache-2.0'] as $name => $licence) {
            $this->assertNotContains($name, $prod, "{$name} is never a runtime dependency");
            $this->assertArrayHasKey($name, $dev, "{$name} is a dev dependency");
            $this->assertContains($licence, $dev[$name], "{$name} licence");
        }
        $this->assertArrayHasKey('studio-design/gesso', $json['require-dev']);
        $this->assertArrayNotHasKey('studio-design/gesso', $json['require']);
        $this->assertContains('studio-design/gesso', $json['extra']['laravel']['dont-discover'], 'its Laravel provider is never registered');
        $this->assertStringNotContainsString('Gesso', (string) @file_get_contents(base_path('bootstrap/cache/packages.php')));
    }
}
