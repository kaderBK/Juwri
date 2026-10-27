<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Services\Aggregators\UberEats\FakeUberEatsClient;
use Juwri\Modules\Channels\Services\Aggregators\UberEats\UberEatsClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class UberEatsIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
        FakeUberEatsClient::reset();
        $this->app->bind(UberEatsClientInterface::class, fn () => new FakeUberEatsClient());
    }

    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Burger', 'price' => 15, 'active' => 1]);

        AggregatorConnection::create([
            'provider' => 'uber_eats', 'store_id' => $r->id,
            'external_store_id' => 'UE-STORE-1', 'status' => 'active',
        ]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'uber_eats', 'provider' => 'uber_eats',
            'secrets' => ['client_secret' => 'ue-secret'], 'active' => true,
        ]);
        return [$r, $item];
    }

    private function hook(array $payload)
    {
        $body = json_encode($payload);
        return $this->call('POST', route('webhook.delivery.inbound', 'uber_eats'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => hash_hmac('sha256', $body, 'ue-secret'),
        ], $body);
    }

    public function test_notification_fetches_order_and_creates_it(): void
    {
        [$r, $item] = $this->store();

        // Uber sends a pointer; the full order is fetched from the (fake) client.
        FakeUberEatsClient::$orders['UE-ORDER-1'] = [
            'id' => 'UE-ORDER-1',
            'store' => ['id' => 'UE-STORE-1'],
            'customer' => ['name' => 'Eater', 'phone' => '+199'],
            'cart' => ['items' => [['external_data' => (string) $item->id, 'quantity' => 2, 'title' => 'Burger']]],
            'payment' => ['charges' => ['total' => ['amount' => 3000]]],
        ];

        $res = $this->hook([
            'event_type' => 'orders.notification',
            'meta' => ['store_id' => 'UE-STORE-1', 'resource_id' => 'UE-ORDER-1'],
        ])->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertSame('uber_eats', $order->source);
        $this->assertSame(1, $order->items()->count());

        // Auto-accepted within the deadline.
        $this->assertContains('accept', array_column(FakeUberEatsClient::$calls, 'action'));
    }

    public function test_unsigned_uber_webhook_rejected(): void
    {
        $this->store();
        $this->postJson(route('webhook.delivery.inbound', 'uber_eats'), [
            'meta' => ['store_id' => 'UE-STORE-1', 'resource_id' => 'UE-ORDER-2'],
        ])->assertStatus(401);
    }

    public function test_replay_idempotent(): void
    {
        [$r, $item] = $this->store();
        FakeUberEatsClient::$orders['UE-ORDER-3'] = [
            'id' => 'UE-ORDER-3', 'store' => ['id' => 'UE-STORE-1'],
            'cart' => ['items' => [['external_data' => (string) $item->id, 'quantity' => 1]]],
        ];
        $payload = ['meta' => ['store_id' => 'UE-STORE-1', 'resource_id' => 'UE-ORDER-3']];

        $a = $this->hook($payload)->assertStatus(201)->json('order_id');
        $b = $this->hook($payload)->assertStatus(201)->json('order_id');
        $this->assertSame($a, $b);
        $this->assertSame(1, Order::where('store_id', $r->id)->count());
    }
}
