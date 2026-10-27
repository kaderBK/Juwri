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
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * SYSTEMS-RUN E-P1: driver PWA + claimable queue + GPS ping.
 */
class DriverPwaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_driver_app' => true]);
    }

    /** @return array{0: Driver, 1: string, 2: Store} */
    private function driver(): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        $raw = Str::random(40);
        $driver = Driver::create([
            'owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'Dan',
            'phone' => UniquePhone::next('5'), 'token' => hash('sha256', $raw), 'active' => true,
        ]);

        return [$driver, $raw, $r];
    }

    private function auth(string $raw): array
    {
        return ['Authorization' => 'Bearer '.$raw, 'Accept' => 'application/json'];
    }

    /**
     * The shell is ANONYMOUS, so it carries no `feature:` middleware: that gate
     * resolves its subject from the logged-in owner and would bounce every
     * driver to the owner upsell page. The shell always renders; the gate lives
     * on /api/v1/driver/*, where it runs after DriverTokenAuth resolves the
     * driver's merchant (asserted below).
     */
    public function test_pwa_shell_renders_for_anonymous_visitors(): void
    {
        $this->get('/driver')->assertOk()->assertSeeHtml('Deliveries');

        config(['settings.admin_allow_driver_app' => false]);
        $this->get('/driver')->assertOk();
    }

    public function test_driver_api_is_gated_when_driver_app_is_disabled(): void
    {
        [$driver, $raw, $r] = $this->driver();
        $this->getJson('/api/v1/driver/me', $this->auth($raw))->assertOk();

        config(['settings.admin_allow_driver_app' => false]);
        $this->getJson('/api/v1/driver/me', $this->auth($raw))->assertForbidden();
    }

    public function test_available_requires_self_claim_optin(): void
    {
        [$driver, $raw, $r] = $this->driver();
        Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'pending']);

        // Off by default → empty.
        $this->getJson('/api/v1/driver/available', $this->auth($raw))->assertOk()->assertJsonCount(0, 'data');

        StoreOption::updateStoreOption($r->id, 'driver_self_claim', '1');
        $this->getJson('/api/v1/driver/available', $this->auth($raw))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_claim_assigns_and_is_atomic(): void
    {
        [$driver, $raw, $r] = $this->driver();
        StoreOption::updateStoreOption($r->id, 'driver_self_claim', '1');
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'pending']);

        $this->postJson('/api/v1/driver/deliveries/'.$order->id.'/claim', [], $this->auth($raw))->assertOk();
        $this->assertSame($driver->id, $order->fresh()->driver_id);

        // Second claim → 409.
        $this->postJson('/api/v1/driver/deliveries/'.$order->id.'/claim', [], $this->auth($raw))->assertStatus(409);
    }

    public function test_p6235_cancelled_unpaid_and_scheduled_orders_are_not_listed_or_claimable(): void
    {
        [$driver, $raw, $r] = $this->driver();
        StoreOption::updateStoreOption($r->id, 'driver_self_claim', '1');
        $base = ['store_id' => $r->id, 'type' => 'delivery', 'delivery_status' => 'pending'];
        $cancelled = Order::create($base + ['status' => 'cancelled']);
        $unpaid = Order::create($base + ['status' => 'new', 'payment_method' => 'stripe', 'is_paid' => 0]);
        $future = Order::create($base + ['status' => 'new', 'scheduled_at' => now()->addDay()]);
        $paid = Order::create($base + ['status' => 'new', 'payment_method' => 'stripe', 'is_paid' => 1]);
        $cash = Order::create($base + ['status' => 'new', 'payment_method' => 'cash']);

        $ids = collect($this->getJson('/api/v1/driver/available', $this->auth($raw))->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$paid->id, $cash->id], $ids);

        foreach ([$cancelled, $unpaid, $future] as $o) {
            $this->postJson('/api/v1/driver/deliveries/'.$o->id.'/claim', [], $this->auth($raw))->assertStatus(409);
            $this->assertNull($o->fresh()->driver_id);
        }
        $this->postJson('/api/v1/driver/deliveries/'.$paid->id.'/claim', [], $this->auth($raw))->assertOk();
    }

    public function test_driver_scoping_on_available(): void
    {
        [$driver, $raw, $r] = $this->driver();
        [$other, $otherRaw, $otherR] = $this->driver();
        StoreOption::updateStoreOption($otherR->id, 'driver_self_claim', '1');
        Order::create(['store_id' => $otherR->id, 'type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'pending']);

        // Our driver (self-claim off + different restaurant) sees nothing.
        $this->getJson('/api/v1/driver/available', $this->auth($raw))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_gps_ping_updates_location(): void
    {
        [$driver, $raw, $r] = $this->driver();
        $this->putJson('/api/v1/driver/location', ['lat' => 24.7, 'lng' => 46.7], $this->auth($raw))->assertOk();
        $this->assertEqualsWithDelta(24.7, (float) $driver->fresh()->last_lat, 0.001);
    }
}
