<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Jobs\PushPosOrderJob;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Channels\Models\PosOrderPush;
use Juwri\Modules\Channels\Services\PosBridge\Clover\CloverProvider;
use Juwri\Modules\Channels\Services\PosBridge\Clover\FakeCloverClient;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeRegistry;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B1-P3: Clover driver.
 */
class PosBridgeCloverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PosBridgeRegistry::flush();
        FakeCloverClient::reset();
        config(['settings.admin_allow_pos_bridge' => true]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function orderWithItem(Store $r, float $price = 3.00, int $qty = 3): array
    {
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Espresso', 'price' => $price, 'active' => 1]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'pickup', 'status' => 'new', 'created_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty]);

        return [$order, $menu];
    }

    private function connect(Store $r): PosBridgeConnection
    {
        return PosBridgeConnection::create([
            'provider' => 'clover', 'store_id' => $r->id, 'status' => 'active',
            'external_location_id' => 'MERCH1',
        ]);
    }

    public function test_registry_exposes_clover_builtin(): void
    {
        $this->assertContains('clover', PosBridgeRegistry::keys());
        $this->assertInstanceOf(CloverProvider::class, PosBridgeRegistry::get('clover'));
    }

    public function test_push_uses_online_order_type_and_adhoc_line(): void
    {
        $r = $this->storeFixture();
        [$order] = $this->orderWithItem($r);
        $conn = $this->connect($r);

        $result = new CloverProvider()->pushOrder($order->fresh(), $conn);

        $this->assertTrue($result->ok);
        $this->assertCount(1, FakeCloverClient::$calls);
        $call = FakeCloverClient::$calls[0];
        $this->assertSame('pos-clv-'.$order->id, $call['key']);
        $this->assertSame('OT_ONLINE', $call['payload']['orderCart']['orderType']['id']);
        $line = $call['payload']['orderCart']['lineItems'][0];
        $this->assertSame('Espresso', $line['name']);
        $this->assertSame(300, $line['price']); // 3.00 → cents
        $this->assertSame(3, $line['unitQty']);
    }

    public function test_mapped_item_pushes_inventory_id(): void
    {
        $r = $this->storeFixture();
        [$order, $menu] = $this->orderWithItem($r);
        PosItemMap::create(['store_id' => $r->id, 'provider' => 'clover', 'menu_id' => $menu->id, 'external_id' => 'CLVINV9']);
        $conn = $this->connect($r);

        new CloverProvider()->pushOrder($order->fresh(), $conn);

        $line = FakeCloverClient::$calls[0]['payload']['orderCart']['lineItems'][0];
        $this->assertSame('CLVINV9', $line['item']['id']);
        $this->assertArrayNotHasKey('price', $line);
    }

    public function test_job_pushes_via_clover_and_is_replay_safe(): void
    {
        $r = $this->storeFixture();
        [$order] = $this->orderWithItem($r);
        $this->connect($r);

        new PushPosOrderJob($order->id, 'clover')->handle();
        new PushPosOrderJob($order->id, 'clover')->handle();

        $this->assertCount(1, FakeCloverClient::$calls, 'replay must not double-push');
        $push = PosOrderPush::where('order_id', $order->id)->where('provider', 'clover')->first();
        $this->assertSame('pushed', $push->status);
        $this->assertStringStartsWith('clv_ord_', $push->external_id);
    }

    public function test_list_items_returns_inventory(): void
    {
        $r = $this->storeFixture();
        $items = new CloverProvider()->listItems($this->connect($r));
        $this->assertNotEmpty($items);
        $this->assertArrayHasKey('external_id', $items[0]);
    }
}
