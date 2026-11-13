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
use Juwri\Modules\Delivery\Services\Couriers\DoorDash\DoorDashClientInterface;
use Juwri\Modules\Delivery\Services\Couriers\DoorDash\FakeDoorDashClient;
use Tests\Support\TestPlans;
use Tests\TestCase;

class DoorDashDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        FakeDoorDashClient::reset();
        $this->app->bind(DoorDashClientInterface::class, fn () => new FakeDoorDashClient);
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
            'store_id' => $r->id, 'feature' => 'doordash', 'provider' => 'doordash',
            'secrets' => ['developer_id' => 'dev', 'client_secret' => 'ddsecret'], 'active' => true,
        ]);
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'delivery', 'status' => 'new',
            'delivery_status' => 'pending', 'address' => '10 Rd', 'phone_number' => '+1', 'created_at' => now(),
        ]);

        return [$r, $order];
    }

    public function test_quote_and_dispatch(): void
    {
        [, $order] = $this->order();
        $delivery = DispatchService::dispatch($order, 'doordash');
        $this->assertInstanceOf(CourierDelivery::class, $delivery);
        $this->assertSame('doordash', $delivery->provider);
        $this->assertEqualsWithDelta(5.49, (float) $delivery->fee, 0.001);
        $this->assertStringStartsWith('mb_', $delivery->external_delivery_id);
    }

    public function test_signed_webhook_updates_status(): void
    {
        [, $order] = $this->order();
        $delivery = DispatchService::dispatch($order, 'doordash');
        $payload = ['delivery_id' => $delivery->external_delivery_id, 'status' => 'delivered'];
        $body = json_encode($payload);
        $this->call('POST', route('webhook.courier.inbound', 'doordash'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DOORDASH_SIGNATURE' => hash_hmac('sha256', $body, 'ddsecret'),
        ], $body)->assertOk();

        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->delivery_status);
    }

    public function test_unsigned_rejected(): void
    {
        [, $order] = $this->order();
        $delivery = DispatchService::dispatch($order, 'doordash');
        $this->postJson(route('webhook.courier.inbound', 'doordash'), [
            'delivery_id' => $delivery->external_delivery_id, 'status' => 'delivered',
        ])->assertStatus(401);
    }
}
