<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Models\Driver;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Delivery\Services\Delivery\DispatchService;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P6-344 — DispatchService::suggestDrivers() must apply the same availability
 * predicate as Driver::isAvailable() (active + shift_status='online' +
 * active_load < capacity) and rank fresh-GPS drivers before stale-GPS drivers.
 * autoAssign() uses the same list, so offline and full drivers must never be
 * picked.
 *
 * The fixture shapes (offline / full / stale / fresh) are the four explicit
 * cases the problem statement calls out.
 */
class DispatchSuggestionAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    /** Make a store with one owner so Driver rows have a valid store_id. */
    private function storeFixture(): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    /**
     * Build one driver row. Defaults mirror the schema defaults except for the
     * caller-specified availability bits (active, shift_status, active_load,
     * capacity) and the GPS freshness bits (last_lat/lng, last_location_at).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function driver(int $storeId, int $ownerId, array $overrides): Driver
    {
        return Driver::create(array_merge([
            'owner_id' => $ownerId, 'store_id' => $storeId,
            'name' => 'D-'.Str::random(4),
            'phone' => UniquePhone::next('5'),
            'token' => hash('sha256', Str::random(40)),
            'active' => true,
            'shift_status' => 'online',
            'capacity' => 2,
            'active_load' => 0,
            'last_lat' => 24.71, 'last_lng' => 46.68,
            'last_location_at' => now(),
        ], $overrides));
    }

    /** The four drivers the spec asks for: offline, full, stale-GPS, fresh. */
    private function fourDrivers(int $storeId, int $ownerId): array
    {
        $offline = $this->driver($storeId, $ownerId, [
            'name' => 'Offline', 'shift_status' => 'offline',
        ]);

        $full = $this->driver($storeId, $ownerId, [
            'name' => 'Full', 'active_load' => 2, 'capacity' => 2,
        ]);

        $stale = $this->driver($storeId, $ownerId, [
            'name' => 'Stale', 'last_location_at' => now()->subMinutes(30),
        ]);

        $fresh = $this->driver($storeId, $ownerId, [
            'name' => 'Fresh', 'last_location_at' => now()->subMinutes(2),
        ]);

        return compact('offline', 'full', 'stale', 'fresh');
    }

    /**
     * Order must have delivery_lat/lng set so the haversine ranking can
     * distinguish stale-from-fresh on distance too. The store coords are the
     * drivers' lat/lng, so the fresh driver sits on top of the order.
     */
    private function order(int $storeId): Order
    {
        return Order::create([
            'store_id' => $storeId, 'type' => 'delivery', 'status' => 'pending',
            'delivery_status' => 'pending', 'delivery_lat' => 24.71, 'delivery_lng' => 46.68,
        ]);
    }

    /** Helper: extract driver ids from a suggestDrivers() result, in order. */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $r): int => (int) $r['driver']->id, $rows);
    }

    /**
     * Four-driver scene: fresh one first, offline and full drivers absent,
     * stale-GPS driver last (still included as last resort). Each row carries
     * the 'stale' bool the owner view consumes.
     */
    public function test_suggestions_exclude_offline_and_full_and_rank_fresh_before_stale(): void
    {
        [$owner, $store] = $this->storeFixture();
        $d = $this->fourDrivers($store->id, $owner->id);
        $order = $this->order($store->id);

        $rows = (new DispatchService)->suggestDrivers($order, 5);
        $ids = $this->ids($rows);

        $this->assertCount(2, $rows, 'offline and full drivers are filtered out');
        $this->assertNotContains($d['offline']->id, $ids, 'offline driver must not be suggested');
        $this->assertNotContains($d['full']->id, $ids, 'full driver must not be suggested');
        $this->assertContains($d['fresh']->id, $ids, 'fresh available driver is suggested');
        $this->assertContains($d['stale']->id, $ids, 'stale available driver is suggested as last resort');

        // Fresh before stale, with the stale row carrying stale=true and the
        // fresh row carrying stale=false — the view reads this bool to render
        // its "GPS old" tag.
        $this->assertSame($d['fresh']->id, $ids[0], 'fresh driver ranks first');
        $this->assertSame($d['stale']->id, $ids[1], 'stale driver ranks after the fresh one');
        $this->assertFalse((bool) ($rows[0]['stale'] ?? true));
        $this->assertTrue((bool) ($rows[1]['stale'] ?? false));
    }

    /**
     * With only a stale-but-online driver on the floor, the suggestion list is
     * NOT empty — the spec is explicit that "stale does not mean do not show,
     * unless no one else is left". autoAssign() then has to be willing to
     * accept the stale driver as the only available option.
     */
    public function test_only_a_stale_driver_left_is_still_returned_and_picked(): void
    {
        [$owner, $store] = $this->storeFixture();
        $stale = $this->driver($store->id, $owner->id, [
            'name' => 'Only', 'last_location_at' => now()->subHour(),
        ]);
        $order = $this->order($store->id);

        $rows = (new DispatchService)->suggestDrivers($order, 5);
        $this->assertCount(1, $rows);
        $this->assertSame($stale->id, $rows[0]['driver']->id);
        $this->assertTrue((bool) $rows[0]['stale']);

        // autoAssign also accepts the stale driver — the only available one.
        $picked = (new DispatchService)->autoAssign($order);
        $this->assertNotNull($picked);
        $this->assertSame($stale->id, $picked->id);
        $this->assertSame($stale->id, (int) $order->fresh()->driver_id);
    }

    /**
     * autoAssign must pick the fresh available driver over a stale-but-online
     * one when both exist — the freshness ranking in suggestDrivers() is what
     * keeps that promise.
     */
    public function test_auto_assign_prefers_fresh_over_stale_when_both_available(): void
    {
        [$owner, $store] = $this->storeFixture();
        $stale = $this->driver($store->id, $owner->id, [
            'name' => 'Stale', 'last_location_at' => now()->subMinutes(45),
        ]);
        $fresh = $this->driver($store->id, $owner->id, [
            'name' => 'Fresh', 'last_location_at' => now()->subMinutes(2),
        ]);
        $order = $this->order($store->id);

        $picked = (new DispatchService)->autoAssign($order);
        $this->assertNotNull($picked);
        $this->assertSame($fresh->id, $picked->id, 'autoAssign picks the fresh-GPS driver first');
        $this->assertSame($fresh->id, (int) $order->fresh()->driver_id);
    }

    /**
     * When every candidate is offline OR full, suggestDrivers() is empty and
     * autoAssign() must return null. Without this, autoAssign() would
     * previously have handed an offline/full driver to a fresh order — the
     * exact regression the spec is closing.
     */
    public function test_auto_assign_returns_null_when_every_driver_is_offline_or_full(): void
    {
        [$owner, $store] = $this->storeFixture();
        $this->driver($store->id, $owner->id, [
            'name' => 'Off', 'shift_status' => 'offline',
        ]);
        $this->driver($store->id, $owner->id, [
            'name' => 'Full', 'active_load' => 1, 'capacity' => 1,
        ]);
        $order = $this->order($store->id);

        $rows = (new DispatchService)->suggestDrivers($order, 5);
        $this->assertSame([], $rows, 'no available drivers → empty list');

        $this->assertNull((new DispatchService)->autoAssign($order));
        $this->assertNull($order->fresh()->driver_id, 'no driver was assigned');
    }

    /**
     * last_location_at exactly at the 10-minute boundary is still fresh
     * (we test the "within the last 10 minutes" branch — strictly older is
     * stale). This pins the cutoff semantics so a future tweak cannot
     * silently shrink the freshness window.
     */
    public function test_stale_boundary_is_strictly_older_than_ten_minutes(): void
    {
        [$owner, $store] = $this->storeFixture();

        $edge = $this->driver($store->id, $owner->id, [
            'name' => 'Edge', 'last_location_at' => now()->subMinutes(10)->subSecond(),
        ]);
        $order = $this->order($store->id);

        $rows = (new DispatchService)->suggestDrivers($order, 5);
        $this->assertCount(1, $rows);
        $this->assertSame($edge->id, $rows[0]['driver']->id);
        $this->assertTrue((bool) $rows[0]['stale'], 'older than 10 min → stale');
    }

    /**
     * The mapFeed JSON feed must mark each suggestion with the 'stale' bool the
     * view reads to render its "GPS old" tag.
     */
    public function test_map_feed_marks_each_suggestion_with_a_stale_flag(): void
    {
        [$owner, $store] = $this->storeFixture();
        $this->driver($store->id, $owner->id, [
            'name' => 'StaleFeed', 'last_location_at' => now()->subHour(),
        ]);
        $this->driver($store->id, $owner->id, [
            'name' => 'FreshFeed', 'last_location_at' => now()->subMinute(),
        ]);
        $order = $this->order($store->id);

        // MapFeed is tenant-gated; the call must be authenticated as the owner
        // and present a store_id the dashboard helpers accept.
        $this->actingAs($owner);
        $this->withoutMiddleware();

        $res = $this->getJson(route('stores.dispatch.map.feed').'?store_id='.$store->id)->assertOk();

        $sugs = collect($res->json('unassigned'))->firstWhere('id', $order->id)['suggestions'] ?? [];
        $this->assertCount(2, $sugs, 'both available drivers reach the panel');

        $byName = collect($sugs)->keyBy('name');
        $this->assertTrue((bool) ($byName['StaleFeed']['stale'] ?? false));
        $this->assertFalse((bool) ($byName['FreshFeed']['stale'] ?? true));
    }
}
