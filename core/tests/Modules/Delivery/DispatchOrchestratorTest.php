<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Models\Driver;
use App\Models\MkDispatchOffer;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\DeliveryService;
use App\Services\Marketplace\DispatchOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Marketplace T4-2: dispatch orchestrator — mode resolution, fleet offer →
 * accept flow, timeout cascade, courier/unassigned fallback, load bookkeeping.
 */
class DispatchOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private DispatchOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orchestrator = new DispatchOrchestrator();
        config(['marketplace.dispatch.offer_batch' => 1, 'marketplace.dispatch.offer_ttl_seconds' => 60]);
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
        ], $mk));
    }

    private function marketStore(array $overrides = []): Store
    {
        return $this->store(array_merge([
            'mk_delivery_modes' => ['fleet'],
            'mk_city_id' => 1,
            'mk_lat' => 24.7136,
            'mk_lng' => 46.6753,
        ], $overrides));
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

    private function storeDriver(Store $store, array $overrides = []): Driver
    {
        return Driver::create(array_merge([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'Store Driver', 'token' => hash('sha256', uniqid('', true)),
            'active' => true,
        ], $overrides));
    }

    /* ----------------------------------------------------- mode resolution */

    public function test_mode_resolution_matrix(): void
    {
        // Legacy store (no modes) → self, regardless of drivers.
        $legacy = $this->store();
        $this->assertSame('self', $this->orchestrator->resolveMode($legacy));

        // self enabled + active own driver → self.
        $selfStore = $this->marketStore(['mk_delivery_modes' => ['self', 'fleet', 'courier']]);
        $this->storeDriver($selfStore);
        $this->assertSame('self', $this->orchestrator->resolveMode($selfStore));

        // self enabled but no active own driver → fleet.
        $noDrivers = $this->marketStore(['mk_delivery_modes' => ['self', 'fleet', 'courier']]);
        $this->assertSame('fleet', $this->orchestrator->resolveMode($noDrivers));

        // self with only an INACTIVE own driver → fleet.
        $inactiveOnly = $this->marketStore(['mk_delivery_modes' => ['self', 'fleet']]);
        $this->storeDriver($inactiveOnly, ['active' => false]);
        $this->assertSame('fleet', $this->orchestrator->resolveMode($inactiveOnly));

        // fleet only → fleet; courier only → courier.
        $this->assertSame('fleet', $this->orchestrator->resolveMode($this->marketStore(['mk_delivery_modes' => ['fleet']])));
        $this->assertSame('courier', $this->orchestrator->resolveMode($this->marketStore(['mk_delivery_modes' => ['courier']])));

        // Only ['self'] with no drivers → courier last resort.
        $stranded = $this->marketStore(['mk_delivery_modes' => ['self']]);
        $this->assertSame('courier', $this->orchestrator->resolveMode($stranded));
    }

    /* ---------------------------------------------------------- scoring */

    public function test_dispatch_offers_nearest_available_of_three(): void
    {
        $store = $this->marketStore();
        $near = $this->fleetDriver(['name' => 'Near', 'last_lat' => 24.7200, 'last_lng' => 46.6800]);
        $this->fleetDriver(['name' => 'Mid', 'last_lat' => 24.8000, 'last_lng' => 46.7500]);
        $this->fleetDriver(['name' => 'Far', 'last_lat' => 25.5000, 'last_lng' => 47.5000]);

        $order = $this->deliveryOrder($store);
        $outcome = $this->orchestrator->dispatch($order);

        $this->assertSame('offered', $outcome);
        $offers = MkDispatchOffer::where('order_id', $order->id)->get();
        $this->assertCount(1, $offers); // offer_batch = 1
        $this->assertSame($near->id, (int) $offers->first()->driver_id);
        $this->assertSame('offered', $offers->first()->status);
        $this->assertNotNull($offers->first()->expires_at);
    }

    public function test_busy_or_offline_drivers_are_never_offered(): void
    {
        $store = $this->marketStore();
        $this->fleetDriver(['name' => 'Full', 'capacity' => 1, 'active_load' => 1]);
        $this->fleetDriver(['name' => 'Off', 'shift_status' => 'offline']);
        $free = $this->fleetDriver(['name' => 'Free']);

        $order = $this->deliveryOrder($store);
        $this->assertSame('offered', $this->orchestrator->dispatch($order));
        $this->assertSame(
            [$free->id],
            MkDispatchOffer::where('order_id', $order->id)->pluck('driver_id')->map(fn ($v) => (int) $v)->all()
        );
    }

    /* ------------------------------------------------------------ accept */

    public function test_accept_assigns_like_self_driver_path_and_increments_load(): void
    {
        config(['marketplace.dispatch.offer_batch' => 2]);
        $store = $this->marketStore();
        $a = $this->fleetDriver(['name' => 'A']);
        $b = $this->fleetDriver(['name' => 'B', 'last_lat' => 24.7300, 'last_lng' => 46.6900]);

        $order = $this->deliveryOrder($store);
        $this->orchestrator->dispatch($order);
        $this->assertCount(2, MkDispatchOffer::where('order_id', $order->id)->pending()->get());

        $offer = MkDispatchOffer::where('order_id', $order->id)->where('driver_id', $a->id)->first();
        $this->assertTrue($this->orchestrator->accept($offer));

        $order->refresh();
        // SAME fields the self-driver path writes:
        $this->assertSame($a->id, (int) $order->driver_id);
        $this->assertSame('assigned', $order->delivery_status);
        $this->assertNotNull($order->assigned_at);

        $this->assertSame(1, (int) $a->fresh()->active_load);
        $this->assertSame('accepted', $offer->fresh()->status);
        $this->assertNotNull($offer->fresh()->responded_at);

        // Sibling offer cancelled.
        $sibling = MkDispatchOffer::where('order_id', $order->id)->where('driver_id', $b->id)->first();
        $this->assertSame('cancelled', $sibling->status);

        // Accepting the cancelled sibling is refused and never reassigns.
        $this->assertFalse($this->orchestrator->accept($sibling));
        $this->assertSame($a->id, (int) $order->fresh()->driver_id);
    }

    public function test_accept_fails_when_order_already_taken(): void
    {
        $store = $this->marketStore();
        $driver = $this->fleetDriver();
        $order = $this->deliveryOrder($store);
        $this->orchestrator->dispatch($order);
        $offer = MkDispatchOffer::where('order_id', $order->id)->first();

        $other = $this->storeDriver($store);
        $order->update(['driver_id' => $other->id]); // raced away

        $this->assertFalse($this->orchestrator->accept($offer));
        $this->assertSame('cancelled', $offer->fresh()->status);
        $this->assertSame(0, (int) $driver->fresh()->active_load);
    }

    public function test_set_status_treats_a_terminal_status_as_final(): void
    {
        $order = $this->deliveryOrder($this->marketStore());

        \App\Services\DeliveryService::setStatus($order, 'delivered');
        $this->assertSame('delivered', $order->fresh()->delivery_status);
        $this->assertNotNull($order->fresh()->delivered_at);

        // A stale/duplicate update to the OTHER terminal must be ignored. delivered→failed
        // would fire order.delivery_failed on a delivered+paid order (running refund/notify)
        // and strip the driver's earnings (which key on delivery_status='delivered').
        \App\Services\DeliveryService::setStatus($order->fresh(), 'failed');
        $this->assertSame('delivered', $order->fresh()->delivery_status, 'terminal is final');
        $this->assertNotNull($order->fresh()->delivered_at, 'delivered_at preserved');
    }

    public function test_reassign_releases_the_previous_fleet_drivers_load(): void
    {
        $store = $this->marketStore();
        $fleet = $this->fleetDriver();
        $order = $this->deliveryOrder($store);

        $this->assertTrue($this->orchestrator->reassign($order, $fleet)['ok']);
        $this->assertSame(1, (int) $fleet->fresh()->active_load, 'fleet driver took the load');
        $this->assertSame($fleet->id, (int) $order->fresh()->driver_id);
        $this->assertSame('assigned', $order->fresh()->delivery_status);

        // Reassigning to a STORE driver must give the fleet driver's capacity back —
        // the bug the owner assign endpoints leaked by writing driver_id raw.
        $storeDriver = $this->storeDriver($store);
        $this->assertTrue($this->orchestrator->reassign($order->fresh(), $storeDriver)['ok']);
        $this->assertSame(0, (int) $fleet->fresh()->active_load, 'fleet load released on reassign');
        $this->assertSame($storeDriver->id, (int) $order->fresh()->driver_id);
    }

    /* ---------------------------------------------------------- cascade */

    public function test_reject_cascades_to_next_nearest_driver(): void
    {
        $store = $this->marketStore();
        $near = $this->fleetDriver(['name' => 'Near', 'last_lat' => 24.7200, 'last_lng' => 46.6800]);
        $next = $this->fleetDriver(['name' => 'Next', 'last_lat' => 24.8000, 'last_lng' => 46.7500]);

        $order = $this->deliveryOrder($store);
        $this->orchestrator->dispatch($order);

        $first = MkDispatchOffer::where('order_id', $order->id)->first();
        $this->assertSame($near->id, (int) $first->driver_id);

        $this->orchestrator->reject($first);

        $this->assertSame('rejected', $first->fresh()->status);
        $second = MkDispatchOffer::where('order_id', $order->id)->pending()->first();
        $this->assertNotNull($second);
        $this->assertSame($next->id, (int) $second->driver_id);
        // The rejecting driver is never re-offered the same order.
        $this->assertSame(1, MkDispatchOffer::where('order_id', $order->id)->where('driver_id', $near->id)->count());
    }

    public function test_expiry_cascades_to_next_driver(): void
    {
        $store = $this->marketStore();
        $near = $this->fleetDriver(['name' => 'Near', 'last_lat' => 24.7200, 'last_lng' => 46.6800]);
        $next = $this->fleetDriver(['name' => 'Next', 'last_lat' => 24.8000, 'last_lng' => 46.7500]);

        $order = $this->deliveryOrder($store);
        $this->orchestrator->dispatch($order);
        MkDispatchOffer::where('order_id', $order->id)->update(['expires_at' => now()->subMinute()]);

        $swept = $this->orchestrator->expireDueOffers();

        $this->assertSame(1, $swept);
        $this->assertSame('expired', MkDispatchOffer::where('driver_id', $near->id)->first()->status);
        $live = MkDispatchOffer::where('order_id', $order->id)->pending()->first();
        $this->assertSame($next->id, (int) $live->driver_id);
    }

    public function test_accepting_an_expired_offer_fails_and_cascades(): void
    {
        $store = $this->marketStore();
        $this->fleetDriver(['name' => 'Slow']);
        $next = $this->fleetDriver(['name' => 'Next', 'last_lat' => 24.8000, 'last_lng' => 46.7500]);

        $order = $this->deliveryOrder($store);
        $this->orchestrator->dispatch($order);
        $offer = MkDispatchOffer::where('order_id', $order->id)->first();
        $offer->update(['expires_at' => now()->subSecond()]);

        $this->assertFalse($this->orchestrator->accept($offer->fresh()));
        $this->assertSame('expired', $offer->fresh()->status);
        $this->assertNull($order->fresh()->driver_id);
        $this->assertSame($next->id, (int) MkDispatchOffer::where('order_id', $order->id)->pending()->value('driver_id'));
    }

    /* ------------------------------------------------------- exhaustion */

    /** Turn off the fake 3rd-party couriers so "no courier connected" is real. */
    private function disableCouriers(): void
    {
        config(['services.uber_direct.driver' => null, 'services.doordash.driver' => null]);
    }

    public function test_exhausted_fleet_falls_back_to_unassigned_when_no_courier(): void
    {
        $this->disableCouriers();
        $store = $this->marketStore();
        $only = $this->fleetDriver(['name' => 'Only']);

        $order = $this->deliveryOrder($store);
        $this->orchestrator->dispatch($order);
        $offer = MkDispatchOffer::where('order_id', $order->id)->first();

        // Sole driver rejects; no 3rd-party courier connected → unassigned, still pending.
        $this->orchestrator->reject($offer);

        $this->assertNull($order->fresh()->driver_id);
        $this->assertSame('pending', $order->fresh()->delivery_status);
        $this->assertSame(0, MkDispatchOffer::where('order_id', $order->id)->pending()->count());
        $this->assertSame(0, (int) $only->fresh()->active_load);
        $this->assertSame(0, \Juwri\Modules\Delivery\Models\CourierDelivery::count());
    }

    public function test_exhausted_fleet_falls_back_to_courier_when_one_is_available(): void
    {
        // Fake courier drivers (default in dev/test) stand in for a connected provider.
        $store = $this->marketStore();
        $this->fleetDriver(['name' => 'Only']);

        $order = $this->deliveryOrder($store);
        $this->orchestrator->dispatch($order);
        $this->orchestrator->reject(MkDispatchOffer::where('order_id', $order->id)->first());

        $this->assertSame(1, \Juwri\Modules\Delivery\Models\CourierDelivery::where('order_id', $order->id)->count());
        $this->assertNull($order->fresh()->driver_id); // courier, not a fleet driver
    }

    public function test_dispatch_with_no_fleet_drivers_returns_unassigned(): void
    {
        $this->disableCouriers();
        $store = $this->marketStore();
        $order = $this->deliveryOrder($store);

        $this->assertSame('unassigned', $this->orchestrator->dispatch($order));
        $this->assertSame(0, MkDispatchOffer::count());
    }

    /* ------------------------------------------------------- completion */

    public function test_complete_decrements_fleet_load_exactly_once(): void
    {
        $store = $this->marketStore();
        $driver = $this->fleetDriver();
        $order = $this->deliveryOrder($store);
        $this->orchestrator->dispatch($order);
        $this->orchestrator->accept(MkDispatchOffer::where('order_id', $order->id)->first());
        $this->assertSame(1, (int) $driver->fresh()->active_load);

        // Wired seam: DeliveryService terminal transition releases capacity.
        DeliveryService::setStatus($order->fresh(), 'delivered');
        $this->assertSame(0, (int) $driver->fresh()->active_load);

        // Re-stamping delivered does not double-decrement.
        DeliveryService::setStatus($order->fresh(), 'delivered');
        $this->assertSame(0, (int) $driver->fresh()->active_load);
    }

    public function test_complete_ignores_store_drivers(): void
    {
        $store = $this->marketStore(['mk_delivery_modes' => []]);
        $driver = $this->storeDriver($store);
        $order = $this->deliveryOrder($store);
        DeliveryService::setStatus($order, 'assigned', $driver->id);

        DeliveryService::setStatus($order->fresh(), 'delivered');

        $this->assertSame(0, (int) $driver->fresh()->active_load);
    }

    /* ---------------------------------------------------- tenant safety */

    public function test_fleet_driver_from_another_city_is_never_offered(): void
    {
        $this->disableCouriers();
        $store = $this->marketStore(['mk_city_id' => 1]);
        $otherCity = $this->fleetDriver(['name' => 'Elsewhere', 'city_id' => 2]);

        $order = $this->deliveryOrder($store);
        $outcome = $this->orchestrator->dispatch($order);

        $this->assertSame('unassigned', $outcome);
        $this->assertSame(0, MkDispatchOffer::where('driver_id', $otherCity->id)->count());
    }

    public function test_dispatch_is_idempotent_for_assigned_orders(): void
    {
        $store = $this->marketStore();
        $this->fleetDriver();
        $order = $this->deliveryOrder($store);
        $order->update(['driver_id' => 999]);

        $this->assertSame('assigned', $this->orchestrator->dispatch($order->fresh()));
        $this->assertSame(0, MkDispatchOffer::count());
    }
}
