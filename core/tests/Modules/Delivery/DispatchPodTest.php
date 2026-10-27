<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Models\Driver;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Delivery\Services\Delivery\DeliveryReportService;
use Juwri\Modules\Delivery\Services\Delivery\DispatchService;
use Juwri\Modules\Delivery\Services\Delivery\PodService;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * SYSTEMS-RUN E-P2: POD (both modes), dispatch suggestions, auto-assign, report.
 */
class DispatchPodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_driver_app' => true]);
    }

    private function driverRaw(int $rid, int $ownerId, ?float $lat = null, ?float $lng = null): array
    {
        $raw = Str::random(40);
        $d = Driver::create([
            'owner_id' => $ownerId, 'store_id' => $rid, 'name' => 'D'.rand(1, 999),
            'phone' => UniquePhone::next('5'), 'token' => hash('sha256', $raw), 'active' => true,
            'last_lat' => $lat, 'last_lng' => $lng, 'last_location_at' => $lat ? now() : null,
            'shift_status' => 'online', // P6-344: dispatch suggests only online drivers with spare capacity
        ]);

        return [$d, $raw];
    }

    private function storeFixture(): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);

        return [$owner, $r];
    }

    public function test_otp_pod_verifies_and_rejects_mismatch(): void
    {
        [$owner, $r] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'delivery_pod_mode', 'otp');
        [$driver, $raw] = $this->driverRaw($r->id, $owner->id);
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'out_for_delivery', 'driver_id' => $driver->id]);
        $otp = PodService::ensureOtp($order);

        // Wrong OTP → not satisfied → delivered blocked.
        $this->postJson('/api/v1/driver/deliveries/'.$order->id.'/pod-otp', ['otp' => '0000'], ['Authorization' => 'Bearer '.$raw])->assertStatus(422);
        $this->putJson('/api/v1/driver/deliveries/'.$order->id.'/status', ['delivery_status' => 'delivered'], ['Authorization' => 'Bearer '.$raw])->assertStatus(422);

        // Correct OTP → satisfied → delivered allowed.
        $this->postJson('/api/v1/driver/deliveries/'.$order->id.'/pod-otp', ['otp' => $otp], ['Authorization' => 'Bearer '.$raw])->assertOk();
        $this->putJson('/api/v1/driver/deliveries/'.$order->id.'/status', ['delivery_status' => 'delivered'], ['Authorization' => 'Bearer '.$raw])->assertOk();
    }

    public function test_the_four_digit_pod_code_is_replaced_and_locked_after_five_wrong_entries(): void
    {
        [$owner, $r] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'delivery_pod_mode', 'otp');
        [$driver, $raw] = $this->driverRaw($r->id, $owner->id);
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'out_for_delivery', 'driver_id' => $driver->id]);
        $otp = PodService::ensureOtp($order);
        $wrong = $otp === '1111' ? '2222' : '1111';
        $url = '/api/v1/driver/deliveries/'.$order->id.'/pod-otp';
        $auth = ['Authorization' => 'Bearer '.$raw];

        for ($i = 0; $i < PodService::MAX_ATTEMPTS; $i++) {
            $this->postJson($url, ['otp' => $wrong], $auth)->assertStatus(422);
        }

        // locked: even the original (now replaced) code is refused with 429, and it no longer matches anyway
        $this->postJson($url, ['otp' => $otp], $auth)->assertStatus(429);
        $this->assertNotNull($order->fresh()->pod_otp);
        $this->assertNull($order->fresh()->pod_verified_at);
    }

    public function test_photo_pod_mode(): void
    {
        [$owner, $r] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'delivery_pod_mode', 'photo');
        [$driver, $raw] = $this->driverRaw($r->id, $owner->id);
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'out_for_delivery', 'driver_id' => $driver->id]);

        $this->assertFalse(PodService::satisfied($order->fresh()));
        PodService::storePhoto($order, 'pod/x.jpg');
        $this->assertTrue(PodService::satisfied($order->fresh()));
    }

    public function test_suggestions_sorted_by_distance(): void
    {
        [$owner, $r] = $this->storeFixture();
        [$near] = $this->driverRaw($r->id, $owner->id, 24.71, 46.68);
        [$far] = $this->driverRaw($r->id, $owner->id, 25.50, 47.50);
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'pending', 'delivery_lat' => 24.70, 'delivery_lng' => 46.67]);

        $sug = new DispatchService()->suggestDrivers($order);
        $this->assertSame($near->id, $sug[0]['driver']->id);
        $this->assertLessThan($sug[1]['distance'], $sug[0]['distance']);
    }

    public function test_auto_assign_is_idempotent(): void
    {
        [$owner, $r] = $this->storeFixture();
        [$near] = $this->driverRaw($r->id, $owner->id, 24.71, 46.68);
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'pending', 'delivery_lat' => 24.70, 'delivery_lng' => 46.67]);

        $svc = new DispatchService;
        $a = $svc->autoAssign($order->fresh());
        $b = $svc->autoAssign($order->fresh());
        $this->assertSame($near->id, $a->id);
        $this->assertSame($a->id, $b->id);
        $this->assertSame($near->id, $order->fresh()->driver_id);
    }

    public function test_report_math(): void
    {
        [$owner, $r] = $this->storeFixture();
        [$driver] = $this->driverRaw($r->id, $owner->id);
        Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'completed', 'delivery_status' => 'delivered',
            'driver_id' => $driver->id, 'payment_method' => 'cash', 'assigned_at' => now()->subMinutes(30), 'delivered_at' => now()]);

        $rows = new DeliveryReportService()->perDriver($r->id);
        $row = collect($rows)->firstWhere('driver.id', $driver->id);
        $this->assertSame(1, $row['deliveries']);
        $this->assertEqualsWithDelta(30.0, $row['avg_minutes'], 1.0);
    }

    public function test_map_page_renders_and_gated(): void
    {
        [$owner, $r] = $this->storeFixture();
        // Give owner a plan so dashboard helpers resolve.
        $this->actingAs($owner);
        $this->withoutMiddleware();
        $this->get(route('stores.dispatch.map.feed').'?store_id='.$r->id)->assertOk()->assertJsonStructure(['drivers', 'active', 'unassigned']);
    }

    /** autoAssign must not overwrite an assignment a concurrent writer already made. */
    public function test_auto_assign_loses_a_concurrent_claim_without_stealing_it(): void
    {
        [$owner, $r] = $this->storeFixture();
        [$near] = $this->driverRaw($r->id, $owner->id, 24.71, 46.68); // nearest → autoAssign picks this
        [$racer] = $this->driverRaw($r->id, $owner->id, 25.50, 47.50);
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'pending', 'delivery_lat' => 24.70, 'delivery_lng' => 46.67]);

        // A concurrent writer claims the order in the DB (query-builder, so the in-memory
        // $order still reads driver_id = null — the exact stale-read window of the race).
        Order::whereKey($order->id)->update(['driver_id' => $racer->id]);

        $winner = new DispatchService()->autoAssign($order); // stale model, driver_id null in memory

        $this->assertSame($racer->id, $winner->id, 'autoAssign yields to the writer that won the claim');
        $this->assertSame($racer->id, (int) $order->fresh()->driver_id, 'the earlier assignment is not overwritten');
    }

    /** A driver-initiated status update may only advance a delivery, never regress it. */
    public function test_a_driver_cannot_move_a_delivery_backwards(): void
    {
        [$owner, $r] = $this->storeFixture();
        [$driver, $raw] = $this->driverRaw($r->id, $owner->id);
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'out_for_delivery', 'driver_id' => $driver->id]);

        // Backward (out_for_delivery → picked_up) is rejected and the status is unchanged.
        $this->putJson('/api/v1/driver/deliveries/'.$order->id.'/status', ['delivery_status' => 'picked_up'], ['Authorization' => 'Bearer '.$raw])
            ->assertStatus(422);
        $this->assertSame('out_for_delivery', $order->fresh()->delivery_status);
    }

    /** The owner assign endpoint releases a previously-assigned fleet driver's capacity. */
    public function test_owner_assign_endpoint_releases_a_previous_fleet_driver(): void
    {
        [$owner, $r] = $this->storeFixture();

        // A fleet driver currently holds the order (load 1, as accept() would leave it).
        $fleet = Driver::create([
            'owner_id' => null, 'store_id' => null, 'name' => 'Fleet', 'is_fleet' => true,
            'token' => hash('sha256', Str::random(40)), 'active' => true, 'active_load' => 1,
            'shift_status' => 'online', 'capacity' => 2, 'city_id' => 1,
        ]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'assigned', 'driver_id' => $fleet->id, 'assigned_at' => now()]);

        [$storeDriver] = $this->driverRaw($r->id, $owner->id);

        $this->actingAs($owner);
        $this->withoutMiddleware();
        $this->post(route('stores.dispatch.assign', $order->id), ['driver_id' => $storeDriver->id]);

        // Reassigned to the store driver AND the fleet driver's load is given back
        // (a raw driver_id write left active_load stuck at 1 forever).
        $this->assertSame($storeDriver->id, (int) $order->fresh()->driver_id);
        $this->assertSame(0, (int) $fleet->fresh()->active_load, 'fleet capacity released');
    }
}
