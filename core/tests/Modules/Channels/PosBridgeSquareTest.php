<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Jobs\PushPosOrderJob;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Channels\Models\PosOrderPush;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeRegistry;
use Juwri\Modules\Channels\Services\PosBridge\Square\FakeSquareClient;
use Juwri\Modules\Channels\Services\PosBridge\Square\SquareProvider;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B1-P2: Square driver.
 */
class PosBridgeSquareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PosBridgeRegistry::flush();
        FakeSquareClient::reset();
        config(['settings.admin_allow_pos_bridge' => true]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');

        return $r;
    }

    private function orderWithItem(Store $r, float $price = 4.50, int $qty = 2): array
    {
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Latte', 'price' => $price, 'active' => 1]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'pickup', 'status' => 'new', 'customer_name' => 'Sara Ali', 'created_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty]);

        return [$order, $menu];
    }

    private function connect(Store $r): PosBridgeConnection
    {
        return PosBridgeConnection::create([
            'provider' => 'square', 'store_id' => $r->id, 'status' => 'active',
            'external_location_id' => 'LOC123',
        ]);
    }

    public function test_registry_exposes_square_builtin(): void
    {
        $this->assertContains('square', PosBridgeRegistry::keys());
        $this->assertInstanceOf(SquareProvider::class, PosBridgeRegistry::get('square'));
    }

    public function test_push_sends_idempotency_key_pickup_and_adhoc_line_item(): void
    {
        $r = $this->storeFixture();
        [$order] = $this->orderWithItem($r);
        $conn = $this->connect($r);

        $result = new SquareProvider()->pushOrder($order->fresh(), $conn);

        $this->assertTrue($result->ok);
        $this->assertNotEmpty($result->externalId);
        $this->assertCount(1, FakeSquareClient::$calls);
        $body = FakeSquareClient::$calls[0];
        $this->assertSame('pos-sq-'.$order->id, $body['idempotency_key']);
        $this->assertSame('LOC123', $body['order']['location_id']);
        $this->assertSame('PICKUP', $body['order']['fulfillments'][0]['type']);
        $this->assertSame('Sara Ali', $body['order']['fulfillments'][0]['pickup_details']['recipient']['display_name']);
        $line = $body['order']['line_items'][0];
        $this->assertSame('Latte', $line['name']);
        $this->assertSame('2', $line['quantity']);
        $this->assertSame(450, $line['base_price_money']['amount']); // 4.50 → cents
        $this->assertSame('USD', $line['base_price_money']['currency']);
    }

    public function test_mapped_item_pushes_catalog_object_id(): void
    {
        $r = $this->storeFixture();
        [$order, $menu] = $this->orderWithItem($r);
        PosItemMap::create(['store_id' => $r->id, 'provider' => 'square', 'menu_id' => $menu->id, 'external_id' => 'SQCAT9']);
        $conn = $this->connect($r);

        new SquareProvider()->pushOrder($order->fresh(), $conn);

        $line = FakeSquareClient::$calls[0]['order']['line_items'][0];
        $this->assertSame('SQCAT9', $line['catalog_object_id']);
        $this->assertArrayNotHasKey('base_price_money', $line);
    }

    public function test_job_pushes_via_square_and_is_replay_safe(): void
    {
        $r = $this->storeFixture();
        [$order] = $this->orderWithItem($r);
        $this->connect($r);

        new PushPosOrderJob($order->id, 'square')->handle();
        new PushPosOrderJob($order->id, 'square')->handle();

        $this->assertCount(1, FakeSquareClient::$calls, 'replay must not double-push');
        $push = PosOrderPush::where('order_id', $order->id)->where('provider', 'square')->first();
        $this->assertSame('pushed', $push->status);
        $this->assertStringStartsWith('sq_ord_', $push->external_id);
    }

    public function test_list_items_returns_catalog(): void
    {
        $r = $this->storeFixture();
        $items = new SquareProvider()->listItems($this->connect($r));
        $this->assertNotEmpty($items);
        $this->assertArrayHasKey('external_id', $items[0]);
    }
}
