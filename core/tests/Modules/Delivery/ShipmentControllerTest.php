<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Delivery\Models\Shipment;
use Tests\TestCase;

/**
 * rev/multi-vertical B4 — shipment/parcel concept.
 *
 * Dashboard order detail marks an order shipped with a tracking number and
 * carrier (creating the one-per-order `shipments` row); the storefront reads
 * that status back, anonymous + track-token-scoped like the existing generic
 * order-track page.
 */
class ShipmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function shipOrder(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'ship', 'status' => 'ready',
            'track_token' => 'tok_'.uniqid(),
        ]);

        return [$owner, $store, $order];
    }

    public function test_owner_marks_an_order_shipped_with_tracking_and_carrier(): void
    {
        [$owner, $store, $order] = $this->shipOrder();

        $res = $this->actingAs($owner)->post(
            route('stores.orders.shipment.update', $order->id).'?store_id='.$store->id,
            ['carrier' => 'DHL', 'tracking_number' => 'TRACK123']
        );

        $res->assertRedirect();

        $shipment = Shipment::where('order_id', $order->id)->first();
        $this->assertNotNull($shipment);
        $this->assertSame('shipped', $shipment->status);
        $this->assertSame('DHL', $shipment->carrier);
        $this->assertSame('TRACK123', $shipment->tracking_number);
        $this->assertNotNull($shipment->shipped_at);

        // B5 tie-in: the order board also reflects "shipped".
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->shipped_at);
    }

    public function test_customer_can_read_the_shipment_status_via_the_track_token(): void
    {
        [$owner, $store, $order] = $this->shipOrder();
        $this->actingAs($owner)->post(
            route('stores.orders.shipment.update', $order->id).'?store_id='.$store->id,
            ['carrier' => 'Aramex', 'tracking_number' => 'AR-999']
        );

        $res = $this->get(route('storefront.shipment.track', $order->track_token));

        $res->assertOk();
        $res->assertJson([
            'status' => 'shipped',
            'carrier' => 'Aramex',
            'tracking_number' => 'AR-999',
        ]);
    }

    public function test_shipment_status_before_shipping_is_pending(): void
    {
        [, , $order] = $this->shipOrder();

        $res = $this->get(route('storefront.shipment.track', $order->track_token));

        $res->assertOk();
        $res->assertJson(['status' => 'pending', 'carrier' => null, 'tracking_number' => null]);
    }

    public function test_an_owner_cannot_mark_another_stores_order_shipped(): void
    {
        [, , $order] = $this->shipOrder();
        $intruder = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $intruderStore = Store::factory()->create(['user_id' => $intruder->id, 'store_type' => 'retail']);

        $res = $this->actingAs($intruder)->post(
            route('stores.orders.shipment.update', $order->id).'?store_id='.$intruderStore->id,
            ['carrier' => 'DHL', 'tracking_number' => 'X']
        );

        $res->assertNotFound();
        $this->assertNull(Shipment::where('order_id', $order->id)->first());
    }
}
