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
use Juwri\Modules\Channels\Services\Aggregators\Keeta\FakeKeetaClient;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaClientInterface;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * N2-P3 — Keeta orders in: signed webhook, OpenItemCode mapping, idempotency,
 * bad-signature rejection, cross-tenant probe, auto-confirm on ingest.
 */
class KeetaIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
        FakeKeetaClient::reset();
        $this->app->bind(KeetaClientInterface::class, fn () => new FakeKeetaClient());
    }

    private function setupStore(string $storeId = 'KEETA-STORE-1'): array
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
            'provider' => 'keeta', 'store_id' => $r->id, 'branch_id' => null,
            'external_store_id' => $storeId, 'status' => 'active',
        ]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'keeta', 'provider' => 'keeta',
            'secrets' => ['app_id' => 'app1', 'app_secret' => 'ksecret'], 'active' => true,
        ]);
        return [$r, $item];
    }

    private function hook(array $payload, string $secret = 'ksecret', ?string $sigOverride = null)
    {
        $body = json_encode($payload);
        $path = '/webhook/delivery/keeta';
        $sig = $sigOverride ?? KeetaSigner::sign($secret, 'POST', $path, [], $body);
        return $this->call('POST', route('webhook.delivery.inbound', 'keeta'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_KEETA_SIGNATURE' => $sig,
        ], $body);
    }

    public function test_signed_order_ingests_maps_openitemcode_and_auto_confirms(): void
    {
        [$r, $item] = $this->setupStore();

        $res = $this->hook([
            'storeId' => 'KEETA-STORE-1', 'orderId' => 'K-1',
            'order' => [
                'commission' => 3.5,
                'customer' => ['name' => 'Sam', 'phone' => '+966500000000'],
                'items' => [['skuCode' => 'sku_' . $item->id, 'quantity' => 2, 'name' => 'Dish']],
            ],
        ])->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertSame('keeta', $order->source);
        $this->assertSame('delivery', $order->type);
        $this->assertEqualsWithDelta(3.5, (float) $order->channel_fee, 0.001);
        $this->assertSame(1, $order->items()->count());

        // Auto-confirmed into Keeta within the accept window.
        $this->assertContains('confirm', array_column(FakeKeetaClient::$calls, 'op'));
    }

    public function test_bad_signature_is_rejected_with_no_order(): void
    {
        [$r, $item] = $this->setupStore();
        $this->hook(['storeId' => 'KEETA-STORE-1', 'orderId' => 'K-2', 'order' => ['items' => []]], 'ksecret', 'deadbeef')
            ->assertStatus(401);
        $this->assertSame(0, Order::count());
    }

    public function test_replay_is_idempotent(): void
    {
        [$r, $item] = $this->setupStore();
        $payload = [
            'storeId' => 'KEETA-STORE-1', 'orderId' => 'K-3',
            'order' => ['items' => [['skuCode' => 'sku_' . $item->id, 'quantity' => 1]]],
        ];
        $first = $this->hook($payload)->assertStatus(201)->json('order_id');
        $second = $this->hook($payload)->assertStatus(201)->json('order_id');
        $this->assertSame($first, $second);
        $this->assertSame(1, Order::count());
    }

    public function test_cross_tenant_store_probe_is_unknown(): void
    {
        $this->setupStore('KEETA-STORE-1');
        // A payload naming a store that isn't connected → 404 (no tenant leak).
        $this->hook(['storeId' => 'SOMEONE-ELSE', 'orderId' => 'K-9', 'order' => ['items' => []]])
            ->assertStatus(404);
    }
}
