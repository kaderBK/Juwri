<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\KdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-293 — an item bump/recall on the KDS must only re-derive the order status
 * while the order is still in the live kitchen flow (new / preparing / ready /
 * delivered). A paid & closed order ('completed') and a table that asked for the
 * bill ('call_bill') must keep their status; recalling an item on a completed
 * order must not reopen it or rewrite completed_at.
 */
class KdsItemRecallBumpGuardIBTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware();

        // P6-293 is independent of messaging; never let a status change reach a
        // real SMS/WhatsApp/push provider while this test runs.
        Http::fake();
        Notification::fake();
        Queue::fake();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 't',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeMenuItem(Store $store): Menu
    {
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'C']);

        return Menu::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Item '.Str::random(4),
            'price' => 5,
            'active' => '1',
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function makeOrder(Store $store, string $status, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'store_id' => $store->id,
            'status' => $status,
            'type' => 'on-table',
            'customer_name' => 'Guest',
            'table_number' => 1,
            'created_at' => now(),
        ], $attributes));
    }

    private function makeLine(Order $order, Menu $menu, string $itemStatus): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $menu->id,
            'quantity' => 1,
            'variation' => 0,
            'status' => $itemStatus,
        ]);
    }

    /** A recall on a paid/closed order must not put it back into the kitchen flow. */
    public function test_completed_order_recall_keeps_status_and_completed_at(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $menu = $this->makeMenuItem($store);
        $completedAt = now()->subMinutes(30);
        $order = $this->makeOrder($store, 'completed', ['completed_at' => $completedAt]);
        $item = $this->makeLine($order, $menu, 'ready');

        KdsService::recallItem($item->fresh());

        $fresh = $order->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertSame(
            $completedAt->timestamp,
            $fresh->completed_at->timestamp,
            'recalling an item on a completed order must not rewrite completed_at'
        );
    }

    /** A bump on a paid/closed order must not reopen it either. */
    public function test_completed_order_bump_keeps_status(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $menu = $this->makeMenuItem($store);
        $order = $this->makeOrder($store, 'completed', ['completed_at' => now()->subMinutes(10)]);
        $item = $this->makeLine($order, $menu, 'preparing');

        KdsService::bumpItem($item->fresh());

        $this->assertSame('completed', $order->fresh()->status);
    }

    /** A table that asked for the bill keeps 'call_bill' when an item is bumped. */
    public function test_call_bill_order_bump_keeps_status(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $menu = $this->makeMenuItem($store);
        $order = $this->makeOrder($store, 'call_bill');
        $item = $this->makeLine($order, $menu, 'queued');

        KdsService::bumpItem($item->fresh());

        $this->assertSame('call_bill', $order->fresh()->status);
    }

    /** And when an item is recalled. */
    public function test_call_bill_order_recall_keeps_status(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $menu = $this->makeMenuItem($store);
        $order = $this->makeOrder($store, 'call_bill');
        $item = $this->makeLine($order, $menu, 'ready');

        KdsService::recallItem($item->fresh());

        $this->assertSame('call_bill', $order->fresh()->status);
    }

    /** Cancelled / unpaid remain untouched (already the behaviour; regression guard). */
    public function test_cancelled_and_unpaid_orders_are_not_resynced(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $menu = $this->makeMenuItem($store);

        $cancelled = $this->makeOrder($store, 'cancelled', ['cancelled_at' => now()]);
        $cancelledItem = $this->makeLine($cancelled, $menu, 'ready');
        KdsService::recallItem($cancelledItem->fresh());
        $this->assertSame('cancelled', $cancelled->fresh()->status);

        $unpaid = $this->makeOrder($store, 'unpaid');
        $unpaidItem = $this->makeLine($unpaid, $menu, 'queued');
        KdsService::bumpItem($unpaidItem->fresh());
        $this->assertSame('unpaid', $unpaid->fresh()->status);
    }

    /** The fix must not stop the live flow: new → preparing → ready → delivered still derives. */
    public function test_live_order_status_still_derives_from_items(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $menu = $this->makeMenuItem($store);
        $order = $this->makeOrder($store, 'new');
        $item = $this->makeLine($order, $menu, 'queued');

        KdsService::bumpItem($item->fresh()); // queued → preparing
        $this->assertSame('preparing', $order->fresh()->status);

        KdsService::bumpItem($item->fresh()); // preparing → ready
        $this->assertSame('ready', $order->fresh()->status);

        KdsService::bumpItem($item->fresh()); // ready → served
        $this->assertSame('delivered', $order->fresh()->status);
    }

    /** 'delivered' stays in the syncable set: recall is still allowed to pull it back. */
    public function test_delivered_order_recall_still_pulls_back(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $menu = $this->makeMenuItem($store);
        $order = $this->makeOrder($store, 'delivered', ['completed_at' => null]);
        $item = $this->makeLine($order, $menu, 'served');

        KdsService::recallItem($item->fresh()); // served → ready → all-ready order

        $this->assertSame('ready', $order->fresh()->status);
    }

    /** The KDS item endpoints report the guarded order status back to the board. */
    public function test_item_endpoints_report_guarded_order_status(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $menu = $this->makeMenuItem($store);

        $completed = $this->makeOrder($store, 'completed', ['completed_at' => now()->subMinutes(5)]);
        $completedItem = $this->makeLine($completed, $menu, 'ready');
        $this->actingAs($owner)
            ->postJson(route('kds.item.recall', $completedItem->id))
            ->assertOk()
            ->assertJson(['order_status' => 'completed']);
        $this->assertSame('completed', $completed->fresh()->status);

        $callBill = $this->makeOrder($store, 'call_bill');
        $callBillItem = $this->makeLine($callBill, $menu, 'queued');
        $this->actingAs($owner)
            ->postJson(route('kds.item.bump', $callBillItem->id))
            ->assertOk()
            ->assertJson(['order_status' => 'call_bill']);
        $this->assertSame('call_bill', $callBill->fresh()->status);
    }
}
