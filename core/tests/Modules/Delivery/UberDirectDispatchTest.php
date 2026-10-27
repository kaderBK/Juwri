<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Delivery\Models\CourierDelivery;
use Juwri\Modules\Delivery\Services\Couriers\DispatchService;
use Juwri\Modules\Delivery\Services\Couriers\UberDirect\FakeUberDirectClient;
use Juwri\Modules\Delivery\Services\Couriers\UberDirect\UberDirectClientInterface;
use Juwri\Modules\Delivery\Services\Couriers\UberDirectProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

class UberDirectDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        FakeUberDirectClient::reset();
        $this->app->bind(UberDirectClientInterface::class, fn () => new FakeUberDirectClient);
    }

    private function order(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        Credential::create([
            'store_id' => $r->id, 'feature' => 'uber_direct', 'provider' => 'uber_direct',
            'secrets' => ['client_id' => 'cid', 'client_secret' => 'csecret'], 'active' => true,
        ]);
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'delivery', 'status' => 'new',
            'delivery_status' => 'pending', 'address' => '10 Rd', 'phone_number' => '+1', 'created_at' => now(),
        ]);

        return [$r, $order];
    }

    public function test_quote_returns_fee_and_eta(): void
    {
        [, $order] = $this->order();
        $quote = new UberDirectProvider()->quote($order);
        $this->assertSame('uber_direct', $quote->provider);
        $this->assertEqualsWithDelta(6.99, $quote->fee, 0.001);
        $this->assertSame(25, $quote->etaMinutes);
    }

    public function test_dispatch_creates_courier_delivery(): void
    {
        [$r, $order] = $this->order();
        $delivery = DispatchService::dispatch($order, 'uber_direct');

        $this->assertInstanceOf(CourierDelivery::class, $delivery);
        $this->assertSame('uber_direct', $delivery->provider);
        $this->assertNotEmpty($delivery->external_delivery_id);
        $this->assertSame('created', $delivery->status);
        $this->assertNotEmpty($delivery->tracking_url);
    }

    public function test_signed_status_webhook_updates_delivery_and_order(): void
    {
        [$r, $order] = $this->order();
        $delivery = DispatchService::dispatch($order, 'uber_direct');

        $payload = [
            'delivery_id' => $delivery->external_delivery_id,
            'status' => 'pickup_complete',
            'courier' => ['location' => ['lat' => 25.1, 'lng' => 51.2]],
        ];
        $body = json_encode($payload);
        $this->call('POST', route('webhook.courier.inbound', 'uber_direct'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => hash_hmac('sha256', $body, 'csecret'),
        ], $body)->assertOk();

        $this->assertSame('picked_up', $delivery->fresh()->status);
        $this->assertEqualsWithDelta(25.1, (float) $delivery->fresh()->courier_lat, 0.001);
        $this->assertSame('out_for_delivery', $order->fresh()->delivery_status);
    }

    public function test_unsigned_courier_webhook_rejected(): void
    {
        [$r, $order] = $this->order();
        $delivery = DispatchService::dispatch($order, 'uber_direct');

        $this->postJson(route('webhook.courier.inbound', 'uber_direct'), [
            'delivery_id' => $delivery->external_delivery_id, 'status' => 'delivered',
        ])->assertStatus(401);
        $this->assertNotSame('delivered', $delivery->fresh()->status);
    }
}
