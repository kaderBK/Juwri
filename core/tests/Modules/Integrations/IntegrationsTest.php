<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Accounting\Jobs\SyncInvoiceJob;
use Juwri\Modules\Accounting\Models\ExternalInvoice;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
        SsrfGuard::$resolver = static fn (string $h): array => ['93.184.216.34']; // P6-68b: sync pins the host
    }

    private function storeFixture(): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function connectAccounting(Store $store): void
    {
        Credential::create([
            'store_id' => $store->id, 'feature' => 'accounting', 'provider' => 'quickbooks',
            'active' => true,
            'secrets' => ['provider' => 'quickbooks', 'access_token' => 'tok', 'base_url' => 'https://qb.example/api'],
        ]);
    }

    // --- Accounting sync ---------------------------------------------------

    public function test_invoice_sync_is_noop_without_connection(): void
    {
        Http::fake();
        $store = $this->storeFixture();
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);

        new SyncInvoiceJob($order->id)->handle();

        Http::assertNothingSent();
        $this->assertSame(0, ExternalInvoice::count());
    }

    public function test_invoice_sync_exports_once_and_is_idempotent(): void
    {
        Http::fake(['qb.example/*' => Http::response(['id' => 'INV-1'], 200)]);
        $store = $this->storeFixture();
        $this->connectAccounting($store);
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);

        new SyncInvoiceJob($order->id)->handle();
        new SyncInvoiceJob($order->id)->handle(); // re-run must not double-export

        Http::assertSentCount(1);
        $this->assertDatabaseHas('external_invoices', ['order_id' => $order->id, 'provider' => 'quickbooks', 'external_id' => 'INV-1']);
        $this->assertSame(1, ExternalInvoice::where('order_id', $order->id)->count());
    }

    // --- Delivery inbound (T3: secured custom-delivery webhook) -------------

    private function withSecret(Store $store): Store
    {
        Credential::create([
            'store_id' => $store->id, 'feature' => 'custom', 'provider' => 'custom',
            'secrets' => ['webhook_secret' => 'k'], 'active' => true,
        ]);

        return $store;
    }

    private function signedDelivery(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhook/delivery/custom', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AGGREGATOR_SIGNATURE' => hash_hmac('sha256', $body, 'k'),
        ], $body);
    }

    public function test_delivery_webhook_creates_order(): void
    {
        $store = $this->withSecret($this->storeFixture());
        $item = Menu::create(['store_id' => $store->id, 'name' => 'D', 'price' => 10, 'active' => 1]);

        $res = $this->signedDelivery([
            'id' => 'UE-123', 'store_id' => $store->id, 'customer_phone' => '+1650',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $res->assertStatus(201)->assertJsonPath('success', true);
        $this->assertDatabaseHas('orders', ['store_id' => $store->id, 'type' => 'delivery']);
        $this->assertDatabaseHas('delivery_orders', ['provider' => 'custom', 'external_id' => 'UE-123']);
    }

    public function test_delivery_webhook_is_idempotent(): void
    {
        $store = $this->withSecret($this->storeFixture());
        $item = Menu::create(['store_id' => $store->id, 'name' => 'D', 'price' => 10, 'active' => 1]);
        $payload = ['id' => 'UE-999', 'store_id' => $store->id, 'items' => [['item_id' => $item->id, 'quantity' => 1]]];

        $first = $this->signedDelivery($payload)->assertStatus(201);
        $second = $this->signedDelivery($payload)->assertStatus(201);

        $this->assertSame($first->json('order_id'), $second->json('order_id'));
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }

    public function test_delivery_webhook_rejects_unsigned_and_unknown(): void
    {
        $store = $this->withSecret($this->storeFixture());
        // Unsigned → 401.
        $this->postJson('/webhook/delivery/custom', ['id' => 'X', 'store_id' => $store->id])->assertStatus(401);
        // Unknown store (signed but bad restaurant) → 404.
        $body = json_encode(['id' => 'X', 'store_id' => 99999]);
        $this->call('POST', '/webhook/delivery/custom', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AGGREGATOR_SIGNATURE' => hash_hmac('sha256', $body, 'k'),
        ], $body)->assertStatus(404);
    }
}
