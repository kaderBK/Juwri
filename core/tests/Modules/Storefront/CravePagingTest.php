<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P4-16: the legacy `crave` theme ships its first cards on a very large catalogue and serves the rest by id. */
class CravePagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // production caches serialise (file / redis); the test `array` store hands back the same model objects, and the happy-hour
        // price (MenuScheduleService::decorate mutates `price` in memory) would then be discounted again on every request
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->seed(ThemeShowcaseSeeder::class);
        $store = Store::where('slug', 'crave-demo')->firstOrFail();
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1']]);
        User::whereKey($store->user_id)->update(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::updateOrCreate(['user_id' => $store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
    }

    /** @return array{items: array<string, mixed>, lazy: array<string, mixed>} */
    private function cfg(string $html): array
    {
        $this->assertSame(1, preg_match('/items:\s+(\{.*?\}),\n\s+lazy:\s+(\{.*?\}),\n\s+lazyUrl:/s', $html, $m), 'the page carries items and lazy');

        return ['items' => json_decode($m[1], true), 'lazy' => json_decode($m[2], true)];
    }

    public function test_a_normal_catalogue_ships_every_item(): void
    {
        $cfg = $this->cfg($this->get('/crave-demo')->assertOk()->getContent());
        $this->assertGreaterThan(12, count($cfg['items']));
        $this->assertSame([], $cfg['lazy']);
    }

    public function test_a_large_catalogue_ships_a_page_and_the_fragment_returns_the_rest_identically(): void
    {
        $full = $this->cfg($this->get('/crave-demo')->assertOk()->getContent())['items'];

        config(['perf.storefront_home_page_threshold' => 10, 'perf.storefront_home_page_items' => 12]);
        $page = $this->cfg($this->get('/crave-demo')->assertOk()->getContent());
        $this->assertLessThan(count($full), count($page['items']));
        $this->assertNotEmpty($page['lazy']);
        $all = array_values(array_unique(array_merge(array_keys($page['items']), array_keys($page['lazy']))));
        $want = array_keys($full);
        sort($all);
        sort($want);
        $this->assertSame($want, $all, 'shipped + lazy = every item');
        foreach ($page['lazy'] as $id => $pair) {
            $this->assertCount(2, $pair, 'category and top-level category');
            $this->assertSame($full[$id]['cat'], $pair[0]);
            $this->assertSame($full[$id]['root'], $pair[1]);
        }

        $ids = array_keys($page['lazy']);
        $res = $this->get('/crave-demo?fragment=crave&ids='.implode(',', $ids))->assertOk();
        $res->assertHeader('Cache-Control');
        $this->assertStringContainsString('application/json', (string) $res->headers->get('Content-Type'));
        $rest = json_decode($res->getContent(), true)['items'];
        $this->assertSame($ids, array_keys($rest));
        $merged = $page['items'] + $rest;
        ksort($merged);
        ksort($full);
        $this->assertEquals($full, $merged, 'the fragment maps an item exactly as the page does');
    }

    public function test_the_fragment_needs_ids_and_caps_them(): void
    {
        $this->get('/crave-demo?fragment=crave')->assertStatus(422);
        $this->get('/crave-demo?fragment=crave&ids='.implode(',', range(1, 121)))->assertStatus(422);
        $res = $this->get('/crave-demo?fragment=crave&ids=999999999')->assertOk();
        $this->assertSame([], json_decode($res->getContent(), true)['items']);
    }
}
