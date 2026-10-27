<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\GoogleOrdering;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Google\GoogleMerchantId;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Services\GoogleOrdering\GoogleOrderingFeedBuilder;
use Juwri\Modules\Storefront\Services\GoogleOrdering\GoogleOrderingMerchantSource;
use Tests\TestCase;

/**
 * P5-24 Google Ordering Redirect — the per-branch ("Google merchant = branch") additions:
 *
 *  - the merchant id is built/parsed by the one shared rule (Juwri\Core\Contracts\Google\GoogleMerchantId);
 *  - a per-branch merchant carries its OWN branch Google Maps place id when the branch has one, and
 *    gets none when the branch has none (never the store-level id);
 *  - a one-branch store keeps the historic store-level place-id hint;
 *  - P5-31: a per-branch merchant advertises its branch's own prep time / delivery fee / minimum order
 *    where the branch sets them, and the store's values where it does not (null = the store's).
 */
class GoogleOrderingBranchTest extends TestCase
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

    /** @return array<string, mixed> the merchant feed entries keyed by merchant_id */
    private function merchantsById(): array
    {
        $feed = app(GoogleOrderingFeedBuilder::class)->buildAll()['merchant_feed.json'];

        return collect($this->entriesOf($feed))->keyBy('merchant_id')->all();
    }

    /** The single eligible-branch store used by the one-branch cases. */
    private function singleBranchStore(string $title): Store
    {
        $store = $this->foodStore('branch-'.Str::lower(Str::random(8)), $title);
        $this->googleOn($store);
        $this->geocodedBranch($store);

        return $store;
    }

    public function test_parse_merchant_id_matches_the_shared_rule(): void
    {
        $this->assertSame(['store' => 12, 'branch' => null], GoogleOrderingMerchantSource::parseMerchantId('store-12'));
        $this->assertSame(['store' => 12, 'branch' => 7], GoogleOrderingMerchantSource::parseMerchantId('store-12-b7'));
        $this->assertNull(GoogleOrderingMerchantSource::parseMerchantId('nope'));
    }

    public function test_a_per_branch_merchant_carries_its_own_branch_place_id(): void
    {
        $store = $this->foodStore('place-'.Str::lower(Str::random(8)), 'Place');
        $this->googleOn($store);
        $a = $this->branchAt($store, 'Riyadh', 24.7136, 46.6753, [], '+966500000001', 'ChIJbranchA');
        $b = $this->branchAt($store, 'Jeddah', 21.4858, 39.1925);

        $merchants = $this->merchantsById();
        $ma = $merchants['store-'.$store->id.'-b'.$a->id];
        $mb = $merchants['store-'.$store->id.'-b'.$b->id];

        $this->assertSame('ChIJbranchA', $ma['matching_hints']['place_id'], 'the branch own place id is the hint');
        $this->assertArrayNotHasKey('matching_hints', $mb, 'a branch with no own place id gets none');
    }

    public function test_a_one_branch_store_falls_back_to_the_store_place_id(): void
    {
        $store = $this->singleBranchStore('Single');
        $store->google_place_id = 'ChIJstoreSingle';
        $store->save();

        $merchants = $this->merchantsById();
        $merchant = $merchants['store-'.$store->id];

        $this->assertArrayHasKey('matching_hints', $merchant);
        $this->assertSame('ChIJstoreSingle', $merchant['matching_hints']['place_id']);
    }

    public function test_a_multi_branch_merchant_never_falls_back_to_the_store_place_id(): void
    {
        $store = $this->foodStore('storeplace-'.Str::lower(Str::random(8)), 'StorePlace');
        $this->googleOn($store);
        $store->google_place_id = 'ChIJstoreLevel';
        $store->save();
        $a = $this->branchAt($store, 'East', 24.7136, 46.6753); // both branches carry no own place id
        $b = $this->branchAt($store, 'West', 21.4858, 39.1925);

        $merchants = $this->merchantsById();
        $this->assertArrayNotHasKey('matching_hints', $merchants['store-'.$store->id.'-b'.$a->id]);
        $this->assertArrayNotHasKey('matching_hints', $merchants['store-'.$store->id.'-b'.$b->id]);
        $this->assertArrayNotHasKey('store-'.$store->id, $merchants, 'no store-level merchant for a multi-branch store');
    }

    /**
     * Branches without overrides (P5-31 columns null): a per-branch merchant advertises the store's values.
     */
    public function test_a_per_branch_service_uses_the_store_fee_min_and_prep(): void
    {
        $store = $this->foodStore('svc-'.Str::lower(Str::random(8)), 'Svc');
        $this->googleOn($store);
        StoreSettings::setMany((int) $store->id, [
            'restaurant_delivery_charge' => '7',
            'min_order_value'            => '50',
            'prep_eta_static'            => '25-35', // p50 = 30 minutes
        ]);
        $a = $this->branchAt($store, 'One', 24.7136, 46.6753);
        $b = $this->branchAt($store, 'Two', 21.4858, 39.1925);

        $feeds = app(GoogleOrderingFeedBuilder::class)->buildAll();
        $services = $this->entriesOf($feeds['service_feed.json']);
        $this->assertNotSame([], $services);

        $perBranch = array_filter($services, fn (array $s): bool => str_starts_with((string) $s['merchant_id'], 'store-'.$store->id.'-b'));

        // Both branch merchants carry the store facts: prep 30 min, minimum 50 SAR (5000 minor).
        $this->assertCount(count($this->offeredTypes($store)) * 2, $perBranch);
        $sawDelivery = false;
        foreach ($perBranch as $service) {
            $this->assertSame(30, $service['eta_min_minutes'] ?? null, 'the store prep time is advertised');
            $this->assertSame(5000, $service['minimum_order']['minor'] ?? null, 'the store minimum order is advertised');
            if (($service['order_type'] ?? null) === 'delivery') {
                $sawDelivery = true;
                $this->assertSame(700, $service['delivery_fee']['minor'] ?? null, 'the store delivery fee is advertised');
            }
        }
        $this->assertTrue($sawDelivery, 'a delivery service is present');
    }

    /** P5-31: a branch's own prep time / delivery fee / minimum order are what its merchant advertises; its sibling keeps the store's. */
    public function test_a_per_branch_service_advertises_the_branch_overrides(): void
    {
        $store = $this->foodStore('svo-'.Str::lower(Str::random(8)), 'Svo');
        $this->googleOn($store);
        StoreSettings::setMany((int) $store->id, [
            'restaurant_delivery_charge' => '7',
            'min_order_value'            => '50',
            'prep_eta_static'            => '25-35', // p50 = 30 minutes
        ]);
        $a = $this->branchAt($store, 'One', 24.7136, 46.6753);
        $b = $this->branchAt($store, 'Two', 21.4858, 39.1925);
        $a->forceFill(['prep_time_minutes' => 15, 'delivery_fee' => '12.5', 'min_order' => '80'])->save();

        $services = $this->entriesOf(app(GoogleOrderingFeedBuilder::class)->buildAll()['service_feed.json']);
        $of = fn ($branch) => array_values(array_filter($services, fn (array $s): bool => $s['merchant_id'] === GoogleMerchantId::format((int) $store->id, (int) $branch->id, false)));
        $this->assertNotSame([], $of($a));
        $this->assertNotSame([], $of($b));

        $sawDelivery = false;
        foreach ($of($a) as $service) {
            $this->assertSame(15, $service['eta_min_minutes'] ?? null, 'the branch prep time');
            $this->assertSame(8000, $service['minimum_order']['minor'] ?? null, 'the branch minimum order');
            if (($service['order_type'] ?? null) === 'delivery') {
                $sawDelivery = true;
                $this->assertSame(1250, $service['delivery_fee']['minor'] ?? null, 'the branch delivery fee');
            }
        }
        $this->assertTrue($sawDelivery);
        foreach ($of($b) as $service) {
            $this->assertSame(30, $service['eta_min_minutes'] ?? null, 'null falls back to the store prep time');
            $this->assertSame(5000, $service['minimum_order']['minor'] ?? null, 'null falls back to the store minimum');
            if (($service['order_type'] ?? null) === 'delivery') {
                $this->assertSame(700, $service['delivery_fee']['minor'] ?? null, 'null falls back to the store fee');
            }
        }
    }
}
