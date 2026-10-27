<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use Juwri\Modules\Delivery\Models\CourierDelivery;
use App\Models\Driver;
use App\Models\MkDispatchOffer;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\Marketplace\DispatchOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Marketplace T4-4: courier fallback auto-wiring. If no fleet driver accepts
 * within marketplace.dispatch.courier_fallback_minutes, fallbackDueOrders()
 * auto-creates a CourierDelivery through the existing T4 courier services —
 * gated on the store allowing 'courier' in mk_delivery_modes, idempotent, and
 * killable via marketplace.dispatch.courier_fallback_enabled.
 */
class CourierFallbackTest extends TestCase
{
    use RefreshDatabase;

    private DispatchOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orchestrator = new DispatchOrchestrator();
        config([
            'marketplace.dispatch.offer_batch' => 1,
            'marketplace.dispatch.offer_ttl_seconds' => 60,
            'marketplace.dispatch.courier_fallback_enabled' => true,
            'marketplace.dispatch.courier_fallback_minutes' => 5,
        ]);
    }

    /* --------------------------------------------------------- fixtures */

    private function store(array $mk = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create(array_merge([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
            'mk_delivery_modes' => ['fleet', 'courier'], 'mk_city_id' => 1,
            'mk_lat' => 24.7136, 'mk_lng' => 46.6753,
        ], $mk));
    }

    private function deliveryOrder(Store $store): Order
    {
        return Order::create([
            'store_id' => $store->id, 'type' => 'delivery', 'status' => 'new',
            'delivery_status' => 'pending', 'created_at' => now(),
        ]);
    }

    private function fleetDriver(array $overrides = []): Driver
    {
        return Driver::create(array_merge([
            'owner_id' => null, 'store_id' => null,
            'name' => 'Fleet ' . Str::random(4),
            'token' => hash('sha256', uniqid('', true)),
            'active' => true, 'is_fleet' => true, 'city_id' => 1,
            'shift_status' => 'online', 'capacity' => 2, 'active_load' => 0,
            'last_lat' => 24.7200, 'last_lng' => 46.6800,
        ], $overrides));
    }

    /** Fake 3rd-party courier client on/off (default in test env is 'fake'). */
    private function disableCouriers(): void
    {
        config(['services.uber_direct.driver' => null, 'services.doordash.driver' => null]);
    }

    private function enableFakeCouriers(): void
    {
        config(['services.uber_direct.driver' => 'fake', 'services.doordash.driver' => 'fake']);
    }

    /**
     * The stuck state T4-4 exists for: an order dispatched to the fleet whose
     * offer expired while NO courier was reachable (the exhaustion cascade
     * came up empty), so it sits unassigned with only dead offers.
     */
    private function stuckOrder(Store $store): Order
    {
        $this->disableCouriers();
        $order = $this->deliveryOrder($store);
        $this->orchestrator->dispatch($order);
        $this->assertSame(1, MkDispatchOffer::where('order_id', $order->id)->pending()->count());

        $this->travel(6)->minutes(); // past offer TTL AND past the fallback window
        $this->orchestrator->expireDueOffers(); // expires + cascade finds nothing

        $this->assertNull($order->fresh()->driver_id);
        $this->assertSame(0, MkDispatchOffer::where('order_id', $order->id)->pending()->count());
        $this->assertSame(0, CourierDelivery::count());

        $this->enableFakeCouriers(); // courier is back
        return $order;
    }

    /* --------------------------------------------------- forced timeout */

    public function test_forced_timeout_creates_courier_delivery(): void
    {
        $store = $this->store();
        $this->fleetDriver();
        $order = $this->stuckOrder($store);

        $escalated = $this->orchestrator->fallbackDueOrders();

        $this->assertSame(1, $escalated);
        $delivery = CourierDelivery::where('order_id', $order->id)->first();
        $this->assertNotNull($delivery);
        // Same shape the Couriers dispatch flow writes:
        $this->assertSame((int) $store->id, (int) $delivery->store_id);
        $this->assertNotNull($delivery->external_delivery_id);
        $this->assertNotNull($delivery->status);
        // Courier delivery ≠ fleet assignment: order keeps no driver_id (track
        // page renders the courier via the CourierDelivery row).
        $this->assertNull($order->fresh()->driver_id);
    }

    public function test_lazy_trigger_from_dispatch_escalates_stuck_orders(): void
    {
        $store = $this->store();
        $this->fleetDriver();
        $stuck = $this->stuckOrder($store);

        // A NEW order being dispatched runs the lazy sweep → the old stuck
        // order gets its courier without any cron.
        $this->fleetDriver(['name' => 'Fresh']);
        $this->orchestrator->dispatch($this->deliveryOrder($store));

        $this->assertSame(1, CourierDelivery::where('order_id', $stuck->id)->count());
    }

    /* ----------------------------------------------------- allow flag */

    public function test_store_without_courier_mode_is_never_escalated(): void
    {
        Log::spy();
        $store = $this->store(['mk_delivery_modes' => ['fleet']]);
        $this->fleetDriver();
        $order = $this->stuckOrder($store);

        $this->assertSame(0, $this->orchestrator->fallbackDueOrders());

        $this->assertSame(0, CourierDelivery::count());
        $this->assertNull($order->fresh()->driver_id);
        $this->assertSame('pending', $order->fresh()->delivery_status); // stays unassigned
        Log::shouldHaveReceived('warning')->with(
            'mk-dispatch: courier fallback due but store does not allow courier',
            \Mockery::on(fn ($ctx) => (int) $ctx['order_id'] === (int) $order->id)
        );
    }

    /* ----------------------------------------------------- idempotency */

    public function test_second_sweep_never_duplicates_courier_delivery(): void
    {
        $store = $this->store();
        $this->fleetDriver();
        $order = $this->stuckOrder($store);

        $this->assertSame(1, $this->orchestrator->fallbackDueOrders());
        $this->assertSame(0, $this->orchestrator->fallbackDueOrders()); // sweep again
        $this->orchestrator->expireDueOffers();                          // and via the scheduler path

        $this->assertSame(1, CourierDelivery::where('order_id', $order->id)->count());
    }

    /* ----------------------------------------------------- kill switch */

    public function test_disabled_config_kills_the_fallback(): void
    {
        $store = $this->store();
        $this->fleetDriver();
        $this->stuckOrder($store);

        config(['marketplace.dispatch.courier_fallback_enabled' => false]);

        $this->assertSame(0, $this->orchestrator->fallbackDueOrders());
        $this->assertSame(0, CourierDelivery::count());
    }

    /* ------------------------------------------- accept beats timeout */

    public function test_fleet_accept_before_timeout_prevents_fallback(): void
    {
        $store = $this->store();
        $driver = $this->fleetDriver();
        $order = $this->deliveryOrder($store);
        $this->orchestrator->dispatch($order);

        $this->assertTrue($this->orchestrator->accept(
            MkDispatchOffer::where('order_id', $order->id)->first()
        ));

        $this->travel(10)->minutes();
        $this->assertSame(0, $this->orchestrator->fallbackDueOrders());

        $this->assertSame(0, CourierDelivery::count());
        $this->assertSame($driver->id, (int) $order->fresh()->driver_id);
    }

    public function test_not_yet_due_orders_are_left_alone(): void
    {
        $store = $this->store();
        $this->fleetDriver();
        $this->disableCouriers();
        $order = $this->deliveryOrder($store);
        $this->orchestrator->dispatch($order);

        // Offer expires (TTL 60s) but the 5-minute fallback window hasn't
        // elapsed → the order waits for a driver, no courier yet.
        $this->travel(2)->minutes();
        $this->orchestrator->expireDueOffers();
        $this->enableFakeCouriers();

        $this->assertSame(0, $this->orchestrator->fallbackDueOrders());
        $this->assertSame(0, CourierDelivery::count());
    }
}
