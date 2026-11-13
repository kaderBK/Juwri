<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Services\Aggregators\DeliverectProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * N6 — Deliverect POS-track hardening: pos_register, pos_syncproducts (CatalogExport →
 * their schema), order-cancel, HMAC verify, status matrix, snooze on sold-out, cross-tenant.
 */
class DeliverectPosTrackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
        // A2: register/sync/cancel are now gated on aggregator_deliverect (dark by default).
        config(['settings.admin_allow_aggregator_deliverect' => true]);
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
        $conn = AggregatorConnection::create(['provider' => 'deliverect', 'store_id' => $r->id, 'external_store_id' => 'CHLINK-1', 'status' => 'active']);
        Credential::create(['store_id' => $r->id, 'feature' => 'deliverect', 'provider' => 'deliverect', 'secrets' => ['webhook_secret' => 'dsecret'], 'active' => true]);
        return [$r, $conn];
    }

    private function call_pos(string $route, array $payload, string $secret = 'dsecret', ?string $sig = null)
    {
        $body = json_encode($payload);
        return $this->call('POST', route($route), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SERVER_AUTHORIZATION_HMAC_SHA256' => $sig ?? hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    public function test_register_persists_channel_link_and_returns_webhook_urls(): void
    {
        [$r, $conn] = $this->store();
        $res = $this->call_pos('webhook.deliverect.register', ['channelLinkId' => 'CHLINK-1'])->assertOk();
        $this->assertSame('CHLINK-1', $conn->fresh()->settings['channel_link_id']);

        // A3: the handshake hands Deliverect the URL set. Required URLs must be
        // absolute HTTPS so a real Deliverect account can POST to them.
        $res->assertJsonStructure(['ordersWebhookURL', 'syncProductsURL', 'storeStatusWebhookURL', 'operationsWebhookURL', 'channelLinkId']);
        $this->assertStringContainsString('/webhook/delivery/deliverect', (string) $res->json('ordersWebhookURL'));
        $this->assertStringContainsString('/webhook/deliverect/syncproducts', (string) $res->json('syncProductsURL'));
        $this->assertSame('CHLINK-1', $res->json('channelLinkId'));
    }

    public function test_syncproducts_returns_menu_schema(): void
    {
        [$r, $conn] = $this->store();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        $burger = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 20, 'active' => '1', 'position' => 1]);

        $res = $this->call_pos('webhook.deliverect.syncproducts', ['channelLinkId' => 'CHLINK-1'])->assertOk();
        $res->assertJsonPath('products.0.plu', (string) $burger->id) // PLU = our menu id
            ->assertJsonPath('products.0.price', 2000);              // cents
        $this->assertNotEmpty($res->json('categories'));
    }

    public function test_bad_signature_rejected_and_cross_tenant_404(): void
    {
        [$r, $conn] = $this->store();
        $this->call_pos('webhook.deliverect.syncproducts', ['channelLinkId' => 'CHLINK-1'], sig: 'bad')->assertStatus(401);
        $this->call_pos('webhook.deliverect.register', ['channelLinkId' => 'UNKNOWN'])->assertStatus(404);
    }

    public function test_order_cancel_cancels_local_order(): void
    {
        [$r, $conn] = $this->store();
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'preparing']);
        \Juwri\Modules\Channels\Models\DeliveryOrder::create(['provider' => 'deliverect', 'external_id' => 'DLV-1', 'order_id' => $order->id, 'store_id' => $r->id]);

        $this->call_pos('webhook.deliverect.ordercancel', ['channelLinkId' => 'CHLINK-1', 'orderId' => 'DLV-1'])->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    /** A partner cancel restores the stock the order deducted (was a leak). */
    public function test_order_cancel_restores_stock(): void
    {
        [$r, $conn] = $this->store();
        // stock_qty 7 = the post-deduct state after selling 3 of 10.
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Burger', 'price' => 20, 'active' => '1', 'track_inventory' => 1, 'stock_qty' => 7]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'preparing']);
        \App\Models\OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 3, 'variation' => 0]);
        \Juwri\Modules\Channels\Models\DeliveryOrder::create(['provider' => 'deliverect', 'external_id' => 'DLV-2', 'order_id' => $order->id, 'store_id' => $r->id]);

        $this->call_pos('webhook.deliverect.ordercancel', ['channelLinkId' => 'CHLINK-1', 'orderId' => 'DLV-2'])->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, (int) $item->fresh()->stock_qty, 'cancel restored the 3');
    }

    public function test_feature_off_refuses_register_and_sync(): void
    {
        [$r, $conn] = $this->store();
        config(['settings.admin_allow_aggregator_deliverect' => false]);
        \App\Services\FeatureAccess::flush();

        // Correctly-signed, known store, but the integration is off → 403, no writes.
        $this->call_pos('webhook.deliverect.register', ['channelLinkId' => 'CHLINK-1'])->assertStatus(403);
        $this->call_pos('webhook.deliverect.syncproducts', ['channelLinkId' => 'CHLINK-1'])->assertStatus(403);
        $this->assertArrayNotHasKey('channel_link_id', (array) ($conn->fresh()->settings ?? []));
    }

    public function test_status_matrix_and_snooze_seam(): void
    {
        [$r, $conn] = $this->store();
        $provider = new DeliverectProvider();

        // A1: the real Deliverect POS ladder — ready = 70 (Pickup Ready),
        // completed = 90 (Finalized). The old matrix used 30/50 (30 is not even a
        // valid POS code) and mapped cancelled → 90 (Finalized).
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'ready']);
        $this->assertSame(70, $provider->statusCodeFor($order));
        $order->status = 'preparing';
        $this->assertSame(50, $provider->statusCodeFor($order));
        $order->status = 'completed';
        $this->assertSame(90, $provider->statusCodeFor($order));
        $order->status = 'cancelled';
        $this->assertSame(110, $provider->statusCodeFor($order), 'cancelled must be 110 Canceled, never 90 Finalized');

        $provider->snoozeProduct($conn, 42, true);
        $this->assertTrue($conn->fresh()->settings['snoozed_plus']['42']);
        $provider->setBusy($conn, true);
        $this->assertTrue($conn->fresh()->settings['busy']);
    }
}
