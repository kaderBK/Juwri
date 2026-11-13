<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\GoogleOrdering;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Services\GoogleOrdering\GoogleOrderingFeedBuilder;
use Juwri\Modules\Storefront\Services\GoogleOrdering\GoogleOrderingFeedValidator;
use Juwri\Modules\Storefront\Services\GoogleOrdering\GoogleOrderingMerchantSource;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;
use Tests\TestCase;

/**
 * P5-20 Google Ordering Redirect — the three feeds (Merchant, Service with action links, Menu).
 *
 * Eligibility, tenancy, storefront-equal money, byte stability and the offline validator.
 */
class GoogleOrderingFeedsTest extends TestCase
{
    use RefreshDatabase;
    use GoogleOrderingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.include_language_code' => false]);
        $this->makeFeedDir();
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->goDir);
        parent::tearDown();
    }

    private function eligible(string $slug, array $switches = []): Store
    {
        $store = $this->foodStore($slug, 'Store', $switches);
        $this->googleOn($store);
        $this->geocodedBranch($store);

        return $store;
    }

    /** @return array<string, array<string, mixed>> */
    private function feeds(): array
    {
        return app(GoogleOrderingFeedBuilder::class)->buildAll();
    }

    public function test_eligible_store_emits_the_three_feeds(): void
    {
        $store = $this->eligible('feeds-'.Str::lower(Str::random(6)));
        $feeds = $this->feeds();

        foreach (['merchant_feed.json', 'service_feed.json', 'menu_feed.json'] as $file) {
            $this->assertArrayHasKey($file, $feeds, $file.' built');
        }

        $merchants = $this->entriesOf($feeds['merchant_feed.json']);
        $merchant = collect($merchants)->firstWhere('merchant_id', 'store-'.$store->id);
        $this->assertNotNull($merchant, 'the eligible store is in the merchants feed');
        $this->assertEqualsWithDelta(24.7136, $merchant['geo']['latitude'], 0.0001);
        $this->assertContains('SAR', $this->stringsIn($feeds['menu_feed.json']), 'the ISO currency is carried');

        // One service per offered order type, each carrying a deep link with its kit key.
        $offered = $this->offeredTypes($store);
        $this->assertSameSize($offered, $this->entriesOf($feeds['service_feed.json']));
        foreach ($offered as $core) {
            $kit = OrderTypeKeys::toKit($core);
            $this->assertNotSame([], $this->actionLinksFor($feeds['service_feed.json'], $kit), "an action link for {$core} ({$kit})");
        }

        // The menu feed carries exactly the storefront catalogue.
        $this->assertCount($this->catalogueItems($store)->count(), $this->menuItemsOf($feeds['menu_feed.json']));
    }

    public function test_store_with_the_switch_off_is_absent(): void
    {
        $store = $this->foodStore('off-'.Str::lower(Str::random(6)), 'Off'); // NOT opted in
        $this->geocodedBranch($store);

        foreach ($this->feeds() as $feed) {
            $this->assertStringNotContainsString('store-'.$store->id, (string) json_encode($feed));
            $this->assertStringNotContainsString($store->slug, (string) json_encode($feed));
        }
    }

    public function test_store_without_a_geocoded_branch_is_absent(): void
    {
        $store = $this->foodStore('nogeo-'.Str::lower(Str::random(6)), 'NoGeo');
        $this->googleOn($store);
        // No branch at all: not a publishable merchant.

        $merchants = $this->entriesOf($this->feeds()['merchant_feed.json']);
        $this->assertNotContains('store-'.$store->id, array_column($merchants, 'merchant_id'));
    }

    public function test_two_stores_never_leak_into_each_others_feeds(): void
    {
        $a = $this->eligible('alpha-'.Str::lower(Str::random(6)));
        $b = $this->eligible('bravo-'.Str::lower(Str::random(6)));

        $merchants = collect($this->entriesOf($this->feeds()['merchant_feed.json']))->keyBy('merchant_id');
        $this->assertTrue($merchants->has('store-'.$a->id));
        $this->assertTrue($merchants->has('store-'.$b->id));

        $service = $this->feeds()['service_feed.json'];
        foreach ($this->actionLinks($service) as $link) {
            $owner = str_contains($link, $a->slug) ? $a : (str_contains($link, $b->slug) ? $b : null);
            $this->assertNotNull($owner, 'every action link names exactly one of the two stores: '.$link);
            $other = $owner->is($a) ? $b : $a;
            $this->assertStringNotContainsString($other->slug, $link, 'an action link never points at the other store');
        }

        foreach ($merchants as $id => $entry) {
            $expected = ((string) $id === 'store-'.$a->id) ? $a->slug : $b->slug;
            $this->assertStringContainsString((string) $expected, (string) $entry['url'], 'the merchant url names its own store');
        }
    }

    public function test_menu_prices_are_the_storefront_minor_units(): void
    {
        $store = $this->eligible('money-'.Str::lower(Str::random(6)));
        $menu = $this->feeds()['menu_feed.json'];

        $ints = $this->intsIn($menu);
        foreach ($this->catalogueItems($store) as $item) {
            $minor = $this->storefrontPriceMinor($store, $item);
            $this->assertContains($minor, $ints, "the storefront price of {$item->name} ({$minor}) is in the feed");
        }
        $this->assertContains('SAR', $this->stringsIn($menu), 'the ISO currency is carried');
    }

    public function test_feeds_are_byte_stable_across_two_builds(): void
    {
        $this->eligible('stable-'.Str::lower(Str::random(6)));

        $first = $this->feeds();
        $second = $this->feeds();

        foreach (['merchant_feed.json', 'service_feed.json', 'menu_feed.json'] as $file) {
            $this->assertSame(
                json_encode($first[$file]),
                json_encode($second[$file]),
                $file.' is byte-stable'
            );
        }
    }

    public function test_action_links_cover_each_locale(): void
    {
        config(['settings.include_language_code' => true]);
        $this->seedActiveLanguages();
        $store = $this->eligible('locale-'.Str::lower(Str::random(6)));
        StoreSettings::setMany((int) $store->id, ['menu_languages' => ['en', 'ar']]);

        $service = $this->feeds()['service_feed.json'];
        $this->assertContains('ar', app(\Juwri\Modules\Storefront\Services\GoogleOrdering\OrderLinks::class)->locales($store));

        foreach ($this->offeredTypes($store) as $core) {
            $kit = OrderTypeKeys::toKit($core);
            $arabic = array_filter(
                $this->actionLinksFor($service, $kit),
                fn ($l) => str_contains($l, '/ar/') && str_contains($l, '/order/'.$kit)
            );
            $this->assertNotSame([], array_values($arabic), "an Arabic action link for {$core}");
        }
    }

    public function test_two_branch_store_publishes_one_merchant_per_branch(): void
    {
        $store = $this->foodStore('multi-'.Str::lower(Str::random(6)), 'Multi');
        $this->googleOn($store);
        $a = $this->branchAt($store, 'Riyadh', 24.7136, 46.6753, ['mon' => ['open' => '09:00', 'close' => '22:00']], '+966500000001');
        $b = $this->branchAt($store, 'Jeddah', 21.4858, 39.1925, [], '+966500000002');

        $feeds = $this->feeds();
        $merchants = collect($this->entriesOf($feeds['merchant_feed.json']))->keyBy('merchant_id');
        $this->assertTrue($merchants->has('store-'.$store->id.'-b'.$a->id));
        $this->assertTrue($merchants->has('store-'.$store->id.'-b'.$b->id));
        $this->assertFalse($merchants->has('store-'.$store->id), 'a multi-branch store has no store-level merchant');

        $ma = $merchants['store-'.$store->id.'-b'.$a->id];
        $mb = $merchants['store-'.$store->id.'-b'.$b->id];
        $this->assertStringContainsString('Multi', $ma['name']);
        $this->assertStringContainsString('Riyadh', $ma['name']);
        $this->assertStringContainsString('Jeddah', $mb['name']);
        $this->assertEqualsWithDelta(24.7136, $ma['geo']['latitude'], 0.0001);
        $this->assertEqualsWithDelta(21.4858, $mb['geo']['latitude'], 0.0001);
        $this->assertNotEquals($ma['geo'], $mb['geo']);
        $this->assertSame('+966500000001', $ma['telephone']);
        $this->assertSame('+966500000002', $mb['telephone']);
        $this->assertArrayHasKey('hours', $ma);
        $this->assertSame('Asia/Riyadh', $ma['hours']['timezone']);
        $this->assertCount(7, $ma['hours']['weekly']);
        $this->assertArrayNotHasKey('matching_hints', $ma, 'a per-branch merchant carries no place-id hint');
        $this->assertArrayNotHasKey('hours', $mb, 'a branch without hours adds none');

        // Each service link carries the branch public id of its own merchant.
        foreach ($this->entriesOf($feeds['service_feed.json']) as $svc) {
            $expected = $svc['merchant_id'] === 'store-'.$store->id.'-b'.$a->id ? $a->public_id : $b->public_id;
            $this->assertNotSame([], $svc['action_links']);
            foreach ($svc['action_links'] as $link) {
                $this->assertStringContainsString('branch='.$expected, $link['url']);
            }
        }

        // One menu per merchant.
        $menuIds = array_column($feeds['menu_feed.json']['menu'], 'merchant_id');
        $this->assertContains('store-'.$store->id.'-b'.$a->id, $menuIds);
        $this->assertContains('store-'.$store->id.'-b'.$b->id, $menuIds);
    }

    public function test_single_branch_store_keeps_store_id_with_no_branch_param(): void
    {
        $store = $this->eligible('single-'.Str::lower(Str::random(6)));

        $feeds = $this->feeds();
        $this->assertContains('store-'.$store->id, array_column($this->entriesOf($feeds['merchant_feed.json']), 'merchant_id'));

        foreach ($this->actionLinks($feeds['service_feed.json']) as $link) {
            $this->assertStringNotContainsString('branch=', $link, 'a single-branch merchant link carries no branch param');
        }
    }

    public function test_inactive_and_ungeocoded_branches_do_not_publish(): void
    {
        $store = $this->foodStore('geo-'.Str::lower(Str::random(6)), 'Geo');
        $this->googleOn($store);
        $live = $this->branchAt($store, 'Live', 24.7136, 46.6753);
        $inactive = Branch::create([
            'store_id' => $store->id, 'name' => 'Closed', 'active' => 0, 'accepting_orders' => 0,
            'timezone' => 'Asia/Riyadh', 'latitude' => 24.8, 'longitude' => 46.7,
        ]);
        $nogeo = Branch::create([
            'store_id' => $store->id, 'name' => 'NoGeo', 'active' => 1, 'accepting_orders' => 1,
            'timezone' => 'Asia/Riyadh',
        ]);

        $json = (string) json_encode($this->feeds()['merchant_feed.json']);
        // Only ONE eligible branch → the historic store-level merchant id.
        $this->assertStringContainsString('"merchant_id":"store-'.$store->id.'"', $json);
        $this->assertStringNotContainsString('-b'.$inactive->id, $json, 'an inactive branch is not a merchant');
        $this->assertStringNotContainsString('-b'.$nogeo->id, $json, 'an ungeocoded branch is not a merchant');
        $this->assertStringNotContainsString('-b'.$live->id, $json, 'one eligible branch keeps store-<id>');
    }

    public function test_a_third_store_never_leaks_across_multi_branch_merchants(): void
    {
        $a = $this->eligible('leak-a-'.Str::lower(Str::random(6)));
        $b = $this->foodStore('leak-b-'.Str::lower(Str::random(6)), 'LeakB');
        $this->googleOn($b);
        $b1 = $this->branchAt($b, 'B One', 25.1, 46.1);
        $b2 = $this->branchAt($b, 'B Two', 26.2, 47.2);
        $c = $this->eligible('leak-c-'.Str::lower(Str::random(6)));

        $feeds = $this->feeds();
        $ids = array_column($this->entriesOf($feeds['merchant_feed.json']), 'merchant_id');
        $this->assertContains('store-'.$b->id.'-b'.$b1->id, $ids);
        $this->assertContains('store-'.$b->id.'-b'.$b2->id, $ids);
        $this->assertContains('store-'.$a->id, $ids);
        $this->assertContains('store-'.$c->id, $ids);

        // No service link ever carries another store's branch public id.
        $branchIds = [];
        foreach ([$a, $b, $c] as $s) {
            $branchIds[$s->id] = Branch::query()->where('store_id', $s->id)->pluck('public_id')->map('strval')->all();
        }
        foreach ($this->entriesOf($feeds['service_feed.json']) as $svc) {
            $parsed = GoogleOrderingMerchantSource::parseMerchantId((string) $svc['merchant_id']);
            $this->assertNotNull($parsed);
            foreach ($svc['action_links'] as $link) {
                foreach ($branchIds as $storeId => $pids) {
                    if ($storeId === $parsed['store']) {
                        continue;
                    }
                    foreach ($pids as $pid) {
                        $this->assertStringNotContainsString($pid, $link['url'], 'a link never names another store\'s branch');
                    }
                }
            }
        }
    }

    public function test_the_validator_drops_a_bad_entry(): void
    {
        $ok = ['merchant_id' => 'store-1', 'name' => 'Cafe', 'url' => 'https://cafe.example',
            'geo' => ['latitude' => 24.7, 'longitude' => 46.6], 'category' => 'restaurant'];
        $payload = ['metadata' => [], 'merchant' => [
            $ok,
            ['merchant_id' => 'store-2', 'geo' => ['latitude' => 91, 'longitude' => 0]] + $ok, // geo out of range
            ['merchant_id' => 'store-3', 'name' => ' '] + $ok, // no name
        ]];

        $out = (new GoogleOrderingFeedValidator)->sanitize('merchant_feed.json', $payload);

        $this->assertSame(['store-1'], array_column($out['payload']['merchant'], 'merchant_id'));
        $this->assertCount(2, $out['problems']);
    }
}
