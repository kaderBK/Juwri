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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class DeliverectIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
        // A2: the inbound path is now gated on aggregator_deliverect (dark by default).
        config(['settings.admin_allow_aggregator_deliverect' => true]);
    }

    private function setup_store(): array
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
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 12, 'active' => 1]);

        AggregatorConnection::create([
            'provider' => 'deliverect', 'store_id' => $r->id, 'branch_id' => null,
            'external_store_id' => 'CHLINK-1', 'status' => 'active',
        ]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'deliverect', 'provider' => 'deliverect',
            'secrets' => ['webhook_secret' => 'dsecret'], 'active' => true,
        ]);
        return [$r, $item];
    }

    private function hook(array $payload)
    {
        $body = json_encode($payload);
        return $this->call('POST', route('webhook.delivery.inbound', 'deliverect'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SERVER_AUTHORIZATION_HMAC_SHA256' => hash_hmac('sha256', $body, 'dsecret'),
        ], $body);
    }

    public function test_deliverect_order_ingests_and_maps_items(): void
    {
        [$r, $item] = $this->setup_store();

        $res = $this->hook([
            'channelLinkId' => 'CHLINK-1', 'orderId' => 'DLV-1', 'channelFee' => 250, // cents → 2.50
            'customer' => ['name' => 'Sam', 'phoneNumber' => '+123'],
            'items' => [['plu' => (string) $item->id, 'quantity' => 2, 'name' => 'Dish']],
        ])->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertSame('deliverect', $order->source);
        $this->assertSame('delivery', $order->type);
        $this->assertEqualsWithDelta(2.50, (float) $order->channel_fee, 0.001);
        $this->assertSame(1, $order->items()->count());
    }

    public function test_unmapped_items_are_surfaced_not_dropped(): void
    {
        [$r, $item] = $this->setup_store();

        $res = $this->hook([
            'channelLinkId' => 'CHLINK-1', 'orderId' => 'DLV-2',
            'items' => [
                ['plu' => (string) $item->id, 'quantity' => 1, 'name' => 'Dish'],
                ['plu' => '999999', 'quantity' => 1, 'name' => 'Mystery Item'],
            ],
        ])->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertStringContainsString('Mystery Item', (string) $order->message);
        $this->assertSame(1, $order->items()->count()); // only the mapped one
    }

    public function test_replay_is_idempotent(): void
    {
        [$r, $item] = $this->setup_store();
        $payload = [
            'channelLinkId' => 'CHLINK-1', 'orderId' => 'DLV-3',
            'items' => [['plu' => (string) $item->id, 'quantity' => 1]],
        ];
        $a = $this->hook($payload)->assertStatus(201)->json('order_id');
        $b = $this->hook($payload)->assertStatus(201)->json('order_id');
        $this->assertSame($a, $b);
        $this->assertSame(1, Order::where('store_id', $r->id)->count());
    }

    public function test_feature_off_refuses_ingest_so_deliverect_fails_over(): void
    {
        [$r, $item] = $this->setup_store();
        // Superadmin (or plan) turns the integration off — a correctly-signed order
        // for a known store must now be refused 403, and NOTHING created.
        config(['settings.admin_allow_aggregator_deliverect' => false]);
        \App\Services\FeatureAccess::flush();

        $this->hook([
            'channelLinkId' => 'CHLINK-1', 'orderId' => 'DLV-OFF',
            'items' => [['plu' => (string) $item->id, 'quantity' => 1]],
        ])->assertStatus(403);
        $this->assertSame(0, Order::where('store_id', $r->id)->count());
    }

    public function test_unknown_channel_link_rejected(): void
    {
        $this->setup_store();
        $body = json_encode(['channelLinkId' => 'NOPE', 'orderId' => 'x', 'items' => []]);
        $this->call('POST', route('webhook.delivery.inbound', 'deliverect'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SERVER_AUTHORIZATION_HMAC_SHA256' => hash_hmac('sha256', $body, 'dsecret'),
        ], $body)->assertStatus(404);
    }
}
