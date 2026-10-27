<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\AvailabilityFeedBuilder;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\MerchantsFeedBuilder;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\RwgMerchantSource;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\ServicesFeedBuilder;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P5-24 (owner decision "Google merchant = branch") — Reserve-with-Google merchants are
 * per BRANCH. A store with two or more eligible branches (active + geocoded) publishes one
 * merchant per branch (`store-<storeId>-b<branchId>`, `dining-<storeId>-b<branchId>`) across
 * the Merchants / Services / Availability feeds; a single-branch store keeps the historic
 * `store-<storeId>` / `dining-<storeId>`. The branch's own place id wins; a one-branch store
 * falls back to the store place id, a multi-branch branch without one inherits nothing.
 */
class RwgBranchMerchantFeedsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', 'UTC')); // a Tuesday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string,mixed> $attrs */
    private function store(array $attrs = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = (string) ($attrs['title'] ?? 'R'.Str::random(6));

        return Store::create(array_merge([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '+97412345', 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function enable(Store $store): void
    {
        StoreOption::updateStoreOption($store->id, 'rwg_reservations_enabled', '1');
    }

    /** @param array<string,array{open?:string,close?:string,closed?:bool}>|null $hours */
    private function branch(
        Store $store,
        string $name,
        float $lat,
        float $lng,
        ?array $hours = null,
        ?string $phone = null,
        ?string $placeId = null,
        bool $active = true,
    ): Branch {
        return Branch::create([
            'store_id' => $store->id, 'name' => $name, 'active' => $active, 'accepting_orders' => 1,
            'timezone' => 'UTC', 'latitude' => $lat, 'longitude' => $lng,
            'phone' => $phone, 'hours' => $hours, 'google_place_id' => $placeId,
        ]);
    }

    /** @return array<string,array{open:string,close:string,closed:bool}> */
    private function allDayHours(): array
    {
        return array_fill_keys(Branch::DAYS, ['open' => '09:00', 'close' => '17:00', 'closed' => false]);
    }

    /** A store with finite covers and a one-day horizon, ready for the availability feed. */
    private function withAvailability(Store $store): void
    {
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '10');
        StoreOption::updateStoreOption($store->id, 'reservation_slot_interval', '60');
        StoreOption::updateStoreOption($store->id, 'reservation_horizon_days', '1');
    }

    public function test_multi_branch_store_publishes_one_merchant_per_branch_in_all_feeds(): void
    {
        $store = $this->store();
        $this->enable($store);
        StoreOption::updateStoreOption($store->id, 'rwg_waitlist_enabled', '1');
        $this->withAvailability($store);
        $hours = $this->allDayHours();
        $a = $this->branch($store, 'Riyadh', 24.7136, 46.6753, $hours, '+966500000001', 'ChIJ-branch-a');
        $b = $this->branch($store, 'Jeddah', 21.4858, 39.1925, $hours, '+966500000002');

        $idA = 'store-'.$store->id.'-b'.$a->id;
        $idB = 'store-'.$store->id.'-b'.$b->id;

        $merchants = collect(app(MerchantsFeedBuilder::class)->build()['merchant'])->keyBy('merchant_id');
        $this->assertTrue($merchants->has($idA));
        $this->assertTrue($merchants->has($idB));
        $this->assertFalse($merchants->has('store-'.$store->id), 'a multi-branch store has no store-level merchant');
        $ma = $merchants[$idA];
        $mb = $merchants[$idB];
        $this->assertStringContainsString((string) $store->title, $ma['name']);
        $this->assertStringContainsString('Riyadh', $ma['name']);
        $this->assertStringContainsString('Jeddah', $mb['name']);
        $this->assertEqualsWithDelta(24.7136, $ma['geo']['latitude'], 0.0001);
        $this->assertEqualsWithDelta(21.4858, $mb['geo']['latitude'], 0.0001);
        $this->assertSame('+966500000001', $ma['telephone']);
        $this->assertSame('+966500000002', $mb['telephone']);
        $this->assertArrayHasKey('hours', $ma);
        $this->assertSame('UTC', $ma['hours']['timezone']);
        $this->assertCount(7, $ma['hours']['weekly']);
        $this->assertSame('ChIJ-branch-a', $ma['matching_hints']['place_id']);
        $this->assertArrayNotHasKey('matching_hints', $mb, 'a branch without a place id inherits none');

        $services = app(ServicesFeedBuilder::class)->build()['service'];
        $this->assertContains('dining-'.$store->id.'-b'.$a->id, array_column($services, 'service_id'));
        $this->assertContains('dining-'.$store->id.'-b'.$b->id, array_column($services, 'service_id'));
        $byMerchant = collect($services)->groupBy('merchant_id');
        $this->assertNotNull($byMerchant[$idA]->firstWhere('service_id', 'waitlist'), 'the waitlist service attaches to branch A');
        $this->assertNotNull($byMerchant[$idB]->firstWhere('service_id', 'waitlist'), 'the waitlist service attaches to branch B');

        $rows = app(AvailabilityFeedBuilder::class)->build()['service_availability'][0]['availability'];
        $this->assertNotEmpty($rows);
        $ids = array_values(array_unique(array_column($rows, 'merchant_id')));
        $this->assertCount(2, $ids, 'both branches emit availability');
        $this->assertContains($idA, $ids);
        $this->assertContains($idB, $ids);
        foreach ($rows as $row) {
            $this->assertContains($row['service_id'], ['dining-'.$store->id.'-b'.$a->id, 'dining-'.$store->id.'-b'.$b->id]);
            $this->assertSame(10, $row['spots_total']);
        }
    }

    public function test_single_branch_store_keeps_the_historic_store_level_ids(): void
    {
        $store = $this->store();
        $this->enable($store);
        $this->branch($store, 'Main', 25.2854, 51.5310);

        $merchants = app(MerchantsFeedBuilder::class)->build()['merchant'];
        $this->assertCount(1, $merchants);
        $this->assertSame('store-'.$store->id, $merchants[0]['merchant_id']);
        $this->assertSame((string) $store->title, $merchants[0]['name']);
        $this->assertSame('+97412345', $merchants[0]['telephone']);
        $this->assertArrayNotHasKey('hours', $merchants[0]);

        $services = app(ServicesFeedBuilder::class)->build()['service'];
        $this->assertCount(1, $services);
        $this->assertSame('store-'.$store->id, $services[0]['merchant_id']);
        $this->assertSame('dining-'.$store->id, $services[0]['service_id']);
    }

    public function test_one_branch_store_place_id_prefers_branch_then_falls_back_to_store(): void
    {
        $branchWins = $this->store(['google_place_id' => 'ChIJ-store-a']);
        $this->enable($branchWins);
        $this->branch($branchWins, 'Main', 25.1, 51.1, null, null, 'ChIJ-branch-a');

        $storeFallback = $this->store(['google_place_id' => 'ChIJ-store-b']);
        $this->enable($storeFallback);
        $this->branch($storeFallback, 'Main', 25.2, 51.2);

        $byId = collect(app(MerchantsFeedBuilder::class)->build()['merchant'])->keyBy('merchant_id');
        $this->assertSame('ChIJ-branch-a', $byId['store-'.$branchWins->id]['matching_hints']['place_id'], 'the branch id wins');
        $this->assertSame('ChIJ-store-b', $byId['store-'.$storeFallback->id]['matching_hints']['place_id'], 'one branch falls back to the store id');
    }

    public function test_multi_branch_place_id_uses_only_the_branch_own_id_and_never_inherits(): void
    {
        $store = $this->store(['google_place_id' => 'ChIJ-store']);
        $this->enable($store);
        $a = $this->branch($store, 'A', 25.1, 51.1, null, null, 'ChIJ-a');
        $b = $this->branch($store, 'B', 25.2, 51.2);

        $byId = collect(app(MerchantsFeedBuilder::class)->build()['merchant'])->keyBy('merchant_id');
        $this->assertSame('ChIJ-a', $byId['store-'.$store->id.'-b'.$a->id]['matching_hints']['place_id']);
        $this->assertArrayNotHasKey('matching_hints', $byId['store-'.$store->id.'-b'.$b->id], 'a multi-branch branch never inherits the store place id');
    }

    public function test_availability_uses_each_branch_and_skips_a_branch_without_hours(): void
    {
        $store = $this->store();
        $this->enable($store);
        $this->withAvailability($store);
        $a = $this->branch($store, 'Open', 25.1, 51.1, $this->allDayHours());
        $b = $this->branch($store, 'NoHours', 25.2, 51.2);

        $rows = app(AvailabilityFeedBuilder::class)->build()['service_availability'][0]['availability'];
        $this->assertNotEmpty($rows);
        $this->assertSame(['store-'.$store->id.'-b'.$a->id], array_values(array_unique(array_column($rows, 'merchant_id'))),
            'only the branch with hours advertises slots');

        $ids = array_column(app(MerchantsFeedBuilder::class)->build()['merchant'], 'merchant_id');
        $this->assertContains('store-'.$store->id.'-b'.$b->id, $ids, 'a branch without hours still publishes a merchant');
    }

    public function test_ineligible_branches_are_not_published_and_one_eligible_keeps_the_store_id(): void
    {
        $store = $this->store();
        $this->enable($store);
        $this->branch($store, 'Live', 25.1, 51.1);
        $this->branch($store, 'Closed', 25.2, 51.2, null, null, null, active: false);
        Branch::create(['store_id' => $store->id, 'name' => 'NoGeo', 'active' => 1, 'accepting_orders' => 1, 'timezone' => 'UTC']);

        $merchants = app(MerchantsFeedBuilder::class)->build()['merchant'];
        $this->assertCount(1, $merchants, 'inactive and ungeocoded branches do not publish');
        $this->assertSame('store-'.$store->id, $merchants[0]['merchant_id'], 'one eligible branch keeps store-<id>');
        $this->assertStringNotContainsString('-b', $merchants[0]['merchant_id']);
    }

    public function test_merchant_id_helpers_accept_the_branch_form(): void
    {
        $this->assertSame(['store' => 12, 'branch' => null], RwgMerchantSource::parseMerchantId('store-12'));
        $this->assertSame(['store' => 12, 'branch' => 7], RwgMerchantSource::parseMerchantId('store-12-b7'));
        $this->assertNull(RwgMerchantSource::parseMerchantId('not-a-merchant'));

        $this->assertSame(12, RwgMerchantSource::storeIdFromMerchant('store-12'));
        $this->assertSame(12, RwgMerchantSource::storeIdFromMerchant('store-12-b7'));
        $this->assertNull(RwgMerchantSource::storeIdFromMerchant('not-a-merchant'));
    }
}
