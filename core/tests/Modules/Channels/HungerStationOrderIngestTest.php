<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Jobs\ProcessHungerStationOrderJob;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationOrderService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * HungerStation P2 — inbound order ingest: idempotent, branch/vendor resolution,
 * sku→menu mapping, source='hungerstation', KDS/notification signal, webhook signature.
 */
class HungerStationOrderIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function restaurantWithVendor(string $vendorId = 'VENDOR_TEST_1'): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = Branch::where('store_id', $store->id)->first();
        $branch->update(['hs_vendor_id' => $vendorId, 'hs_chain_id' => 'CHAIN_TEST_1']);

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 30, 'active' => '1', 'hs_sku' => 'SKU-001']);

        return [$store, $branch];
    }

    private function payload(string $vendorId = 'VENDOR_TEST_1', string $orderId = 'HSORDER-1'): array
    {
        return [
            'order_id' => $orderId, 'order_code' => 'HS-4821', 'status' => 'RECEIVED',
            'vendor_id' => $vendorId, 'chain_id' => 'CHAIN_TEST_1',
            'transport_type' => 'LOGISTICS_DELIVERY', 'picking_mode' => 'partner',
            'items' => [
                ['sku' => 'SKU-001', 'name' => 'Pizza', 'quantity' => 2],
                ['sku' => 'SKU-UNKNOWN', 'name' => 'Mystery', 'quantity' => 1],
            ],
            'customer' => ['name' => 'Guest', 'phone_masked' => '+9665***123'],
        ];
    }

    public function test_ingest_creates_order_maps_items_and_links_delivery_order(): void
    {
        [$store] = $this->restaurantWithVendor();

        $res = app(HungerStationOrderService::class)->ingest($this->payload());
        $order = $res['order'];

        $this->assertNotNull($order);
        $this->assertSame('hungerstation', $order->source);
        $this->assertSame('delivery', $order->type);
        $this->assertSame('HSORDER-1', $order->hs_order_id);
        $this->assertFalse((bool) $order->seen);
        // mapped item created; unmapped sku flagged, not dropped
        $this->assertSame(1, $order->items()->count());
        $this->assertStringContainsString('SKU-UNKNOWN', (string) $order->message);
        $this->assertDatabaseHas('delivery_orders', ['provider' => 'hungerstation', 'external_id' => 'HSORDER-1', 'order_id' => $order->id]);
        $this->assertDatabaseHas('hungerstation_event_logs', ['direction' => 'in', 'kind' => 'order.received', 'hs_order_id' => 'HSORDER-1']);
    }

    public function test_ingest_is_idempotent_on_replay(): void
    {
        $this->restaurantWithVendor();
        $svc = app(HungerStationOrderService::class);

        $first = $svc->ingest($this->payload());
        $second = $svc->ingest($this->payload());

        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['order']->id, $second['order']->id);
        $this->assertSame(1, Order::where('hs_order_id', 'HSORDER-1')->count());
    }

    public function test_retry_after_a_mid_ingest_failure_creates_the_order_exactly_once(): void
    {
        // P6-64: the job now retries; a failure after the claim must not leave an orphan
        // claim that makes the retry report "duplicate" and drop the order.
        $this->restaurantWithVendor();
        $svc = app(HungerStationOrderService::class);
        $fail = true;
        OrderItem::creating(function () use (&$fail) {
            if ($fail) {
                $fail = false;
                throw new \RuntimeException('simulated failure mid-ingest');
            }
        });

        try {
            $svc->ingest($this->payload());
            $this->fail('first attempt should throw');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, Order::where('hs_order_id', 'HSORDER-1')->count());
        $this->assertDatabaseMissing('delivery_orders', ['provider' => 'hungerstation', 'external_id' => 'HSORDER-1']);

        $retry = $svc->ingest($this->payload());
        $this->assertFalse($retry['duplicate']);
        $this->assertNotNull($retry['order']);
        $this->assertSame(1, $retry['order']->items()->count());

        $again = $svc->ingest($this->payload());
        $this->assertTrue($again['duplicate']);
        $this->assertSame(1, Order::where('hs_order_id', 'HSORDER-1')->count());
    }

    public function test_unknown_vendor_is_rejected_and_logged(): void
    {
        $res = app(HungerStationOrderService::class)->ingest($this->payload('NOPE'));
        $this->assertNull($res['order']);
        $this->assertSame('unknown vendor', $res['error']);
        $this->assertDatabaseHas('hungerstation_event_logs', ['kind' => 'order.unknown_vendor', 'ok' => 0]);
    }

    public function test_webhook_rejects_known_vendor_when_secret_is_missing(): void
    {
        config(['services.hungerstation.verify_signatures' => true]);
        $this->restaurantWithVendor();
        Queue::fake();

        $this->postJson(route('webhook.hungerstation'), $this->payload())->assertUnauthorized();
        Queue::assertNothingPushed();
    }

    public function test_webhook_queues_job_with_valid_signature(): void
    {
        config(['services.hungerstation.verify_signatures' => true]);
        [$store] = $this->restaurantWithVendor();
        Credential::create([
            'store_id' => $store->id, 'feature' => 'hungerstation', 'provider' => 'hungerstation',
            'secrets' => ['webhook_secret' => 'shh'], 'active' => true,
        ]);
        Queue::fake();
        $payload = $this->payload();
        $signature = hash_hmac('sha256', json_encode($payload), 'shh');

        $this->withHeaders(['X-HS-Signature' => $signature])
            ->postJson(route('webhook.hungerstation'), $payload)
            ->assertStatus(202);
        Queue::assertPushed(ProcessHungerStationOrderJob::class);
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        config(['services.hungerstation.verify_signatures' => true]);
        [$store, $branch] = $this->restaurantWithVendor();
        Credential::create([
            'store_id' => $store->id, 'feature' => 'hungerstation', 'provider' => 'hungerstation',
            'secrets' => ['webhook_secret' => 'shh'], 'active' => true,
        ]);
        Queue::fake();

        $this->withHeaders(['X-HS-Signature' => 'wrong'])
            ->postJson(route('webhook.hungerstation'), $this->payload())
            ->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_production_ignores_the_verify_signatures_off_switch(): void
    {
        // P6-249 sweep: HS_VERIFY_SIGNATURES=false must not open the webhook in production.
        config(['services.hungerstation.verify_signatures' => false]);
        $this->app['env'] = 'production';
        $this->restaurantWithVendor();
        Queue::fake();

        $this->postJson(route('webhook.hungerstation'), $this->payload())->assertUnauthorized();
        Queue::assertNothingPushed();
    }
}
