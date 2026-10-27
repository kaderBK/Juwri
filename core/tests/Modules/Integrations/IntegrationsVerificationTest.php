<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Channels\Models\DeliveryOrder;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Services\Aggregators\AggregatorRegistry;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\FakeKeetaClient;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaClientInterface;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaProvider;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * V — INTEGRATIONS-RUN verification pass: every driver is registered behind its seam,
 * and a representative full delivery loop (webhook → order → status-back) works end to
 * end against the Fake. Per-track tests cover each driver in depth; this asserts the
 * wiring holds together.
 */
class IntegrationsVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pos_and_aggregator_drivers_are_registered(): void
    {
        $pos = PosBridgeRegistry::keys();
        foreach (['square', 'clover', 'foodics', 'marn'] as $k) {
            $this->assertContains($k, $pos, "POS driver {$k} not registered");
        }

        $agg = AggregatorRegistry::keys();
        foreach (['deliverect', 'uber_eats', 'keeta', 'jahez'] as $k) {
            $this->assertContains($k, $agg, "Aggregator driver {$k} not registered");
        }
    }

    public function test_representative_delivery_loop_webhook_to_status_back(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
        FakeKeetaClient::reset();
        $this->app->bind(KeetaClientInterface::class, fn () => new FakeKeetaClient());

        // Tenant + menu + connection.
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
        AggregatorConnection::create(['provider' => 'keeta', 'store_id' => $r->id, 'external_store_id' => 'S1', 'status' => 'active']);
        Credential::create(['store_id' => $r->id, 'feature' => 'keeta', 'provider' => 'keeta', 'secrets' => ['app_secret' => 'sec'], 'active' => true]);

        // 1) Signed webhook → order created (one money path) + auto-confirmed.
        $payload = ['storeId' => 'S1', 'orderId' => 'V-1', 'order' => ['items' => [['skuCode' => 'sku_' . $item->id, 'quantity' => 2]]]];
        $body = json_encode($payload);
        $sig = \Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaSigner::sign('sec', 'POST', '/webhook/delivery/keeta', [], $body);
        $res = $this->call('POST', route('webhook.delivery.inbound', 'keeta'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_KEETA_SIGNATURE' => $sig,
        ], $body)->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertSame('keeta', $order->source);
        $this->assertTrue(DeliveryOrder::where('provider', 'keeta')->where('external_id', 'V-1')->exists());
        $this->assertContains('confirm', array_column(FakeKeetaClient::$calls, 'op'));

        // 2) Status-back: mark ready → pushStatus fires prepare.
        $order->status = 'ready';
        $order->save();
        $conn = AggregatorConnection::resolve('keeta', 'S1');
        new KeetaProvider()->pushStatus($order, $conn);
        $this->assertContains('prepare', array_column(FakeKeetaClient::$calls, 'op'));

        // 3) Idempotent replay → no duplicate order.
        $this->call('POST', route('webhook.delivery.inbound', 'keeta'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_KEETA_SIGNATURE' => $sig,
        ], $body)->assertStatus(201);
        $this->assertSame(1, Order::where('store_id', $r->id)->count());
    }
}
