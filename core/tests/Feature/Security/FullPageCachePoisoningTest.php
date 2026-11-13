<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Security;

use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Http\Cache\FullPageCacheKey;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Http\Middleware\CacheMarketingResponse;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Http\Middleware\CacheStorefrontResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-79: web-cache poisoning sweep over both full-page caches (storefront `publicView`, marketing pages).
 *
 * A forged request (proxy headers, Host variants, unkeyed query params) primes the cache; the next clean guest
 * request must then either miss (the forged render is under another key) or be served a body carrying none of
 * the forged inputs. Runs with every proxy trusted ('*') and with the default (none trusted).
 */
class FullPageCachePoisoningTest extends TestCase
{
    use RefreshDatabase;

    /** Strings a forged request may smuggle into a page; never in a clean guest's cached body. */
    private const MARKERS = ['attacker.test', ':8443', ':1337', 'evilcampaign', 'evil.test', 'http://localhost'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();

        // lift only the test-runner guard of both caches
        app()->instance(CacheStorefrontResponse::class, new class extends CacheStorefrontResponse
        {
            protected function disabledForTesting(): bool
            {
                return false;
            }
        });
        app()->instance(CacheMarketingResponse::class, new class extends CacheMarketingResponse
        {
            protected function disabledForTesting(): bool
            {
                return false;
            }
        });
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], -1);
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: array<string, string>, 2: string}> proxies, forged headers, forged URL suffix/host */
    public static function vectors(): array
    {
        $cases = [];
        foreach (['trust-all' => '*', 'default' => ''] as $label => $proxies) {
            $cases["$label: X-Forwarded-Host"] = [$proxies, ['X-Forwarded-Host' => 'attacker.test'], ''];
            $cases["$label: X-Forwarded-Host with port"] = [$proxies, ['X-Forwarded-Host' => 'localhost:8443'], ''];
            $cases["$label: X-Forwarded-Proto http"] = [$proxies, ['X-Forwarded-Proto' => 'http'], ''];
            $cases["$label: X-Forwarded-Port"] = [$proxies, ['X-Forwarded-Port' => '1337'], ''];
            $cases["$label: X-Original-URL / X-Rewrite-URL"] = [$proxies, ['X-Original-URL' => '/evil.test', 'X-Rewrite-URL' => '/evil.test'], ''];
            $cases["$label: Host upper case"] = [$proxies, [], 'host:LOCALHOST'];
            $cases["$label: Host trailing dot"] = [$proxies, [], 'host:localhost.'];
            $cases["$label: Host with port"] = [$proxies, [], 'host:localhost:8443'];
            $cases["$label: tracking tags"] = [$proxies, [], 'query:utm_source=evilcampaign&utm_campaign=evilcampaign&fbclid=evilcampaign&gclid=evilcampaign'];
            $cases["$label: lang param"] = [$proxies, [], 'query:lang=evilcampaign'];
            $cases["$label: currency param"] = [$proxies, [], 'query:currency=evilcampaign'];
        }

        return $cases;
    }

    /** @param array<string, string> $headers */
    #[DataProvider('vectors')]
    public function test_a_forged_request_never_poisons_the_storefront_page(string $proxies, array $headers, string $variant): void
    {
        $store = $this->makeRenderableStore();
        config(['perf.storefront_fullpage_cache' => true, 'perf.storefront_fullpage_cache_canary' => $store->slug]);

        $this->assertNotPoisoned('/'.$store->slug, $proxies, $headers, $variant, 'X-Storefront-Cache');
    }

    /** @param array<string, string> $headers */
    #[DataProvider('vectors')]
    public function test_a_forged_request_never_poisons_a_marketing_page(string $proxies, array $headers, string $variant): void
    {
        $this->withoutMiddleware([Installed::class]);

        $this->assertNotPoisoned('/mcp', $proxies, $headers, $variant, 'X-Marketing-Cache');
    }

    public function test_the_cache_keys_carry_scheme_and_port_and_drop_tracking_tags(): void
    {
        $https = Request::create('https://shop.test/menu?utm_source=x&branch_id=2');
        $http = Request::create('http://shop.test/menu?branch_id=2');
        $port = Request::create('https://shop.test:8443/menu?branch_id=2');
        $this->assertNotSame(FullPageCacheKey::origin($https), FullPageCacheKey::origin($http));
        $this->assertNotSame(FullPageCacheKey::origin($https), FullPageCacheKey::origin($port));

        FullPageCacheKey::stripTrackingParams($https);
        $this->assertSame('/menu?branch_id=2', FullPageCacheKey::uri($https));
        $this->assertStringNotContainsString('utm_source', $https->fullUrl());
        $this->assertSame(['branch_id' => '2'], $https->query());
    }

    public function test_campaign_tags_still_share_one_storefront_entry_and_never_reach_its_links(): void
    {
        $store = $this->makeRenderableStore();
        config(['perf.storefront_fullpage_cache' => true, 'perf.storefront_fullpage_cache_canary' => $store->slug]);

        $first = $this->get('https://localhost/'.$store->slug.'?utm_source=evilcampaign');
        $this->assertSame('MISS', $first->headers->get('X-Storefront-Cache'));
        $this->assertStringNotContainsString('evilcampaign', (string) $first->getContent());
        $this->assertSame('HIT', $this->get('https://localhost/'.$store->slug)->headers->get('X-Storefront-Cache'));
    }

    public function test_lang_and_a_raw_branch_id_bypass_the_storefront_cache(): void
    {
        $store = $this->makeRenderableStore();
        config(['perf.storefront_fullpage_cache' => true, 'perf.storefront_fullpage_cache_canary' => $store->slug]);

        foreach (['lang=ar', 'branch_id=1abc', 'branch_id=01', 'branch_id[]=1'] as $q) {
            $this->assertNull($this->get('https://localhost/'.$store->slug.'?'.$q)->headers->get('X-Storefront-Cache'), "?$q must bypass");
        }
    }

    public function test_the_storefront_key_carries_the_remembered_menu_language_and_geo_hint(): void
    {
        $src = (string) file_get_contents(app_path('Modules/Storefront/Http/Middleware/CacheStorefrontResponse.php'));
        $this->assertStringContainsString('$this->menuLanguageCookie()', $src);
        $this->assertStringContainsString('visitor_country()', $src);
        $this->assertStringContainsString('FullPageCacheKey::origin($request)', $src);
    }

    /** @param array<string, string> $headers */
    private function assertNotPoisoned(string $path, string $proxies, array $headers, string $variant, string $cacheHeader): void
    {
        config(['trustedproxy.proxies' => $proxies === '*' ? '*' : []]);

        // what a clean https guest is served with an empty cache — markers it carries legitimately are not findings
        $baseline = $this->clean($path, $cacheHeader);
        $this->assertSame('MISS', $baseline->headers->get($cacheHeader), 'the page must be cacheable for this sweep to mean anything');
        $legit = array_filter(self::MARKERS, fn ($m) => str_contains((string) $baseline->getContent(), $m));
        Cache::flush();

        [$kind, $value] = $variant === '' ? ['', ''] : explode(':', $variant, 2);
        $url = match ($kind) {
            'host' => 'https://'.$value.$path,
            'query' => 'https://localhost'.$path.'?'.$value,
            default => 'https://localhost'.$path,
        };
        $this->withHeaders($headers)->get($url);
        $this->flushHeaders();

        $clean = $this->clean($path, $cacheHeader);
        if ($clean->headers->get($cacheHeader) !== 'HIT') {
            $this->assertSame('MISS', $clean->headers->get($cacheHeader));

            return; // the forged render sits under another key
        }
        $body = (string) $clean->getContent();
        foreach (array_diff(self::MARKERS, $legit) as $marker) {
            $this->assertStringNotContainsString($marker, $body, "cached page poisoned with {$marker}");
        }
    }

    private function clean(string $path, string $cacheHeader): TestResponse
    {
        $res = $this->get('https://localhost'.$path);
        $res->assertOk();

        return $res;
    }

    private function makeRenderableStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        StoreOption::updateStoreOption($store->id, 'store_template', 'juwri');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);
        StorefrontMenuCache::version((int) $store->id);

        return $store;
    }
}
