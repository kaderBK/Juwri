<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Delivery\Models\CourierDelivery;
use Tests\TestCase;

/**
 * P6-100: courier webhooks (Uber Direct / DoorDash) are retried and not ordered. A replay has no second effect,
 * a late status never moves the delivery or the order backwards, and a late GPS fix never overwrites a newer one.
 */
class CourierWebhookReplayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'courier-test-secret';

    private Order $order;

    private CourierDelivery $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->seedStores(41);
        Credential::create([
            'store_id' => 41, 'feature' => 'uber_direct', 'provider' => 'uber_direct',
            'secrets' => ['client_secret' => self::SECRET], 'active' => true,
        ]);
        $this->order = Order::create(['store_id' => 41, 'type' => 'delivery', 'status' => 'new', 'delivery_status' => 'pending', 'created_at' => now()]);
        $this->delivery = CourierDelivery::create([
            'order_id' => $this->order->id, 'store_id' => 41, 'provider' => 'uber_direct',
            'external_delivery_id' => 'del_1', 'status' => 'created', 'fee' => 0,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function send(array $payload, ?string $signature = null): TestResponse
    {
        $raw = json_encode(['delivery_id' => 'del_1'] + $payload);

        return $this->call('POST', '/webhook/courier/uber_direct', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => $signature ?? hash_hmac('sha256', $raw, self::SECRET),
        ], $raw);
    }

    public function test_unsigned_event_is_rejected(): void
    {
        $this->send(['status' => 'delivered'], 'bad')->assertStatus(401);
        $this->assertSame('created', $this->delivery->fresh()->status);
    }

    public function test_replayed_event_has_no_second_effect(): void
    {
        $event = ['status' => 'pickup', 'created' => '2026-10-13T10:00:00Z'];
        $this->send($event)->assertOk()->assertJsonMissing(['duplicate' => true]);
        $pickedAt = $this->order->fresh()->picked_up_at;

        $this->travel(5)->minutes();
        $this->send($event)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame('picked_up', $this->delivery->fresh()->status);
        $this->assertEquals($pickedAt, $this->order->fresh()->picked_up_at);
        $this->assertSame(1, DB::table('webhook_receipts')->where('provider', 'courier_uber_direct')->count());
    }

    public function test_out_of_order_updates_never_regress_status(): void
    {
        $this->send(['status' => 'pickup_complete', 'created' => '2026-10-13T10:05:00Z'])->assertOk();
        $this->assertSame('out_for_delivery', $this->order->fresh()->delivery_status);

        // A late "pickup" and "created" arrive after the courier already left the store.
        $this->send(['status' => 'pickup', 'created' => '2026-10-13T10:01:00Z'])->assertOk();
        $this->send(['status' => 'created', 'created' => '2026-10-13T09:59:00Z'])->assertOk();
        $this->assertSame('picked_up', $this->delivery->fresh()->status);
        $this->assertSame('out_for_delivery', $this->order->fresh()->delivery_status);

        $this->send(['status' => 'delivered', 'created' => '2026-10-13T10:20:00Z'])->assertOk();
        // Terminal is final: a late cancel or pickup changes nothing.
        $this->send(['status' => 'canceled', 'created' => '2026-10-13T10:21:00Z'])->assertOk();
        $this->send(['status' => 'dropoff', 'created' => '2026-10-13T10:10:00Z'])->assertOk();

        $this->assertSame('delivered', $this->delivery->fresh()->status);
        $this->assertSame('delivered', $this->order->fresh()->delivery_status);
    }

    public function test_location_is_only_replaced_by_a_newer_fix(): void
    {
        $this->send(['status' => 'pickup', 'created' => '2026-10-13T10:10:00Z', 'courier' => ['location' => ['lat' => 24.5, 'lng' => 46.5]]])->assertOk();
        $this->send(['status' => 'pickup', 'created' => '2026-10-13T10:02:00Z', 'courier' => ['location' => ['lat' => 1.0, 'lng' => 1.0]]])->assertOk();

        $fresh = $this->delivery->fresh();
        $this->assertEqualsWithDelta(24.5, $fresh->courier_lat, 0.0001);
        $this->assertEqualsWithDelta(46.5, $fresh->courier_lng, 0.0001);

        $this->send(['status' => 'dropoff', 'created' => '2026-10-13T10:15:00Z', 'courier' => ['location' => ['lat' => 24.6, 'lng' => 46.6]]])->assertOk();
        $this->assertEqualsWithDelta(24.6, $this->delivery->fresh()->courier_lat, 0.0001);

        // After delivery the courier's position is no longer tracked.
        $this->send(['status' => 'delivered', 'created' => '2026-10-13T10:20:00Z'])->assertOk();
        $this->send(['status' => 'delivered', 'created' => '2026-10-13T10:25:00Z', 'courier' => ['location' => ['lat' => 9.0, 'lng' => 9.0]]])->assertOk();
        $this->assertEqualsWithDelta(24.6, $this->delivery->fresh()->courier_lat, 0.0001);
    }
}
