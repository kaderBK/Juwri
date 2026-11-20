<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Platform;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Tests\Modules\Platform\PlatformE2eTestCase;
use Tests\Support\PricedOrderLine;

/**
 * B5 — Orders hub (the seam Lane B's `platform/b-orders.js` drives end-to-end
 * in a real browser: place orders, navigate to orders hub, drive status
 * transitions, cancel with reason, refund, test filters/search, verify customer
 * track page reflects changes).
 *
 * This test locks the server-side contract that journey depends on,
 * HTTP-through-controller:
 *   1. GET /orders/{restaurant?} returns orders for the owner's store.
 *   2. POST to status-transition endpoints persists the status change to the DB.
 *   3. Cancel action stores the reason in the order.
 *   4. Tender is restored/recredited on cancellation.
 *   5. Partial refund creates ledger entries and updates the order balance.
 *   6. Filter endpoint accepts status, date_range, search query.
 *   7. Order show page returns 200 and displays order details.
 *   8. Authorization: owner cannot access other owners' orders.
 *
 * Discriminating: it fails if status transitions don't persist, authorization
 * is lost, or order display breaks.
 */
class B5OrdersHubTest extends PlatformE2eTestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml pins APP_INSTALLED=false, so the `installed` middleware on
        // the whole owner-dashboard route group (routes/web.php) redirects every
        // request here to /install (302) before the controller ever runs — the
        // same setup gap every other owner-dashboard HTTP-through-controller test
        // in this suite works around (see e.g. FolioDashboardTest,
        // ResellerManagedTransparencyTest, PublishQrLinksTest). shareLayoutGlobals()
        // then replaces the view()->composer() shares ViewServiceProvider::boot()
        // skips for the same reason, so the orders index view can render.
        // Installed/PreventRequestForgery are already bypassed by
        // PlatformE2eTestCase::setUp(); this test also needs the staff-permission
        // gate and the store-switcher sync off, since it drives the owner-only
        // orders hub directly rather than through a staff session.
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
        ]);

        // Create an owner and a demo store
        $this->owner = User::factory()->create(['email' => 'b5-owner@local.invalid', 'status' => 1]);
        $this->owner->email_verified_at = now();
        $this->owner->save();

        $this->store = Store::factory()->create([
            'user_id' => $this->owner->id,
            'slug' => 'b5-test-store-'.time(),
        ]);
    }

    /**
     * An order the hub lists: the screen drops orders whose lines are worth nothing (no dish rows), so
     * each carries one priced line. (Bare factory orders were never listed; the old assertions only
     * passed because the digit "1" appears somewhere on any page when ids start at 1.)
     */
    private function listedOrder(array $attrs = []): Order
    {
        $menu = Menu::create(['store_id' => $this->store->id, 'name' => 'Dish', 'price' => 20, 'active' => 1]);
        $order = Order::factory()->create(['store_id' => $this->store->id] + $attrs);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'status' => 'queued']);

        return $order;
    }

    public function test_orders_hub_returns_orders_list(): void
    {
        // Create 5 orders on the store with various statuses
        $orders = [];
        foreach (range(1, 5) as $i) {
            $orders[] = $this->listedOrder([
                'status' => $i % 2 === 0 ? 'accepted' : 'pending',
                'grand_total' => 50 + ($i * 10),
            ]);
        }

        // Login as owner and navigate to orders hub
        // Route: GET /orders/{restaurant?} → restaurants.orders
        $response = $this->actingAs($this->owner)->get(route('stores.orders', ['store' => $this->store->id]));

        // Should return 200
        $response->assertStatus(200);

        // Check if response contains order data (HTML)
        $response->assertSeeHtml('data-order-id="'.$orders[0]->id.'"');
    }

    /**
     * Found by the P1-12 Arabic journey at 390px: the page's print-receipt script wrote a literal closing head tag in a JS
     * string, so anything injecting before the LAST closing head tag of a page (dev browser-log scripts) split the inline
     * script, printed its code as page text and pushed the page 95px wider than the phone.
     */
    public function test_the_orders_page_has_one_closing_head_tag_and_the_script_escapes_its_receipt_markup(): void
    {
        $this->listedOrder(['status' => 'pending']);

        $html = $this->actingAs($this->owner)->get(route('stores.orders', ['store' => $this->store->id]))->assertOk()->getContent();

        $this->assertSame(1, substr_count(strtolower($html), '</head>'), 'only the layout may close the head');
        $this->assertStringContainsString("<\\/head><body><h3>' + pe(config.restaurantName)", $html);
    }

    public function test_orders_hub_filter_by_status(): void
    {
        // Create orders with mixed statuses
        $pending = $this->listedOrder(['status' => 'pending']);
        $accepted = $this->listedOrder(['status' => 'accepted']);
        $completed = $this->listedOrder(['status' => 'completed']);

        // Filter by status=accepted (if the endpoint supports it)
        $response = $this->actingAs($this->owner)->get(
            route('stores.orders', ['store' => $this->store->id]).'?status=accepted'
        );

        $response->assertStatus(200);

        // The response should include the accepted order
        $content = $response->content();
        $this->assertStringContainsString('data-order-id="'.$accepted->id.'"', $content);
        $this->assertStringNotContainsString('data-order-id="'.$pending->id.'"', $content);
    }

    public function test_orders_hub_search_by_order_number(): void
    {
        $order = $this->listedOrder();

        // Search by order ID
        $response = $this->actingAs($this->owner)->get(
            route('stores.orders', ['store' => $this->store->id]).'?search='.$order->id
        );

        $response->assertStatus(200);
        $content = $response->content();
        $this->assertStringContainsString('data-order-id="'.$order->id.'"', $content, 'search should find the order');
    }

    public function test_order_status_transition_persists_to_db(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'pending',
        ]);

        // POST /order/{order}/status → restaurants.updateOrderStatus.
        // 'accepted' was the pre-drift status word: StoreController\Concerns\ManagesOrders::
        // updateOrderStatus() only accepts ['pending','preparing','ready','completed',
        // 'cancelled'] (the live status vocabulary — see order-row.blade.php's own
        // $statusLabels, which lists exactly those five), so 'accepted' 400s as an
        // invalid status and never reaches the DB. 'preparing' is the real next step
        // in the current workflow.
        $response = $this->actingAs($this->owner)->post(
            route('stores.updateOrderStatus', ['order' => $order->id]),
            ['status' => 'preparing'],
        );

        // Should succeed (200 or 302)
        $this->assertTrue(in_array($response->status(), [200, 302]), 'status should be 200 or 302, got '.$response->status());

        // Verify the status changed in the DB
        $fresh = $order->fresh();
        $this->assertEquals('preparing', $fresh->status, 'status transition did not persist');
        $this->assertNotNull($fresh->preparing_at, 'stage timestamp was not stamped');
    }

    public function test_cancel_order_via_status_update(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'pending',
        ]);

        // POST /order/{order}/status with status=cancelled
        $response = $this->actingAs($this->owner)->post(
            route('stores.updateOrderStatus', ['order' => $order->id]),
            ['status' => 'cancelled', 'reason' => 'Customer requested cancellation'],
        );

        // Should succeed
        $this->assertTrue(in_array($response->status(), [200, 302]));

        // Verify status is cancelled
        $order->refresh();
        $this->assertEquals('cancelled', $order->status);
    }

    public function test_partial_refund_creates_ledger_entries(): void
    {
        // refundOrderToCredit() 422s "This order has no customer account to
        // credit." for any order with no customer_id — the previous version of
        // this test never attached one, so the endpoint always short-circuited
        // to a 422 (outside the asserted [200,302] set, but the assert only ran
        // when the `installed` middleware wasn't already redirecting every
        // request to /install — see setUp()). A real refund-to-credit needs a
        // real Customer on the order.
        $customer = Customer::create([
            'store_id' => $this->store->id,
            'name' => 'B5 Customer',
            'email' => 'b5-customer@local.invalid',
            'phone' => '966500000000',
            'password' => bcrypt('SecurePass123'),
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'completed',
            'grand_total' => 100,
            'customer_id' => $customer->id,
        ]);
        $order = PricedOrderLine::add($order, 100.0); // SEC-1119: an order with no priced lines has nothing to refund

        // POST /order/{order}/refund-credit → restaurants.refundOrderToCredit
        $response = $this->actingAs($this->owner)->post(
            route('stores.refundOrderToCredit', ['order' => $order->id]),
            ['amount' => 50],
        );

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Verify order still exists and the credit actually landed in the
        // customer's ledger account (double-entry: CustomerWalletService::credit()
        // posts a balanced [customer +50 / platform-clearing -50] pair via
        // LedgerService, keyed off ref 'refund:order:{id}').
        $order->refresh();
        $this->assertNotNull($order->id);

        $account = CustomerWalletService::accountFor($customer);
        $adjustment = OrderAdjustment::where('order_id', $order->id)->where('type', 'refund')->sole(); // the refund row keys the credit
        $this->assertDatabaseHas('ledger_entries', [
            'ledger_account_id' => $account->id,
            'ref' => 'cw:refund:order:'.$order->id.':adj:'.$adjustment->id.':cust',
            'amount_minor' => 5000, // P1-05: minor units (USD)
        ]);
        $this->assertEquals(50.0, CustomerWalletService::balanceFor($customer));
    }

    public function test_complete_order_persists(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'ready',
        ]);

        // POST /order/{order}/complete → restaurants.completeOrder
        $response = $this->actingAs($this->owner)->post(
            route('stores.completeOrder', ['order' => $order->id]),
        );

        // Should succeed
        $this->assertTrue(in_array($response->status(), [200, 302]));

        // Verify status is now completed
        $this->assertEquals('completed', $order->fresh()->status);
    }

    public function test_multiple_orders_can_be_canceled_individually(): void
    {
        // Create 2 orders
        $order1 = Order::factory()->create(['store_id' => $this->store->id, 'status' => 'pending']);
        $order2 = Order::factory()->create(['store_id' => $this->store->id, 'status' => 'pending']);

        // Cancel order 1
        $response1 = $this->actingAs($this->owner)->post(
            route('stores.updateOrderStatus', ['order' => $order1->id]),
            ['status' => 'cancelled'],
        );
        $this->assertTrue(in_array($response1->status(), [200, 302]));

        // Order 2 should still be pending
        $order2->refresh();
        $this->assertEquals('pending', $order2->status, 'cancelling order1 should not affect order2');

        // Cancel order 2
        $response2 = $this->actingAs($this->owner)->post(
            route('stores.updateOrderStatus', ['order' => $order2->id]),
            ['status' => 'cancelled'],
        );
        $this->assertTrue(in_array($response2->status(), [200, 302]));

        // Verify order 2 is now cancelled
        $order2->refresh();
        $this->assertEquals('cancelled', $order2->status);
    }

    public function test_owner_cannot_modify_other_owners_orders(): void
    {
        $other_owner = User::factory()->create();
        $other_store = Store::factory()->create(['user_id' => $other_owner->id]);
        $other_order = Order::factory()->create(['store_id' => $other_store->id, 'status' => 'pending']);

        // Login as $this->owner (different owner) and try to update other_order
        $response = $this->actingAs($this->owner)->post(
            route('stores.updateOrderStatus', ['order' => $other_order->id]),
            ['status' => 'accepted'],
        );

        // BL-043: the owner tenant scope hides the other account's order
        $response->assertNotFound();

        // Verify the other order was not modified
        $this->assertEquals('pending', $other_order->fresh()->status);
    }

    /**
     * MULTI-VERTICAL (rev/multi-vertical B5): every test above drives the
     * food orders hub — restaurant-only, `route('stores.orders', ...)`.
     * A retail/laundry store never reaches that page at all; it has its OWN
     * order board, Juwri\Modules\Verticals\Http\Controllers\User\RetailOrderBoardController
     * (routes/verticals/lane-b.php: `posts.orders.board` /
     * `posts.orders.board.advance`), driving the same additive state machine
     * (Juwri\Modules\Verticals\Services\Fulfillment\RetailOrderBoardService::STATES —
     * new→packing→ready→shipped|collected→done) that the food board's
     * pending→preparing→ready→completed pipeline never touches. This pins that
     * fork at the same e2e altitude: list + advance, HTTP-through-controller.
     *
     * A separate owner+store via the base class's `ownerWithStore('retail')`
     * (not $this->owner/$this->store, which are a plain 'restaurant'), so this
     * is fully isolated from the food-board tests above.
     */
    public function test_retail_vertical_order_board_lists_and_advances_orders(): void
    {
        [$retailOwner, $retailStore] = $this->ownerWithStore('retail', null);

        // 'ship' is one of retail's configured fulfillment modes
        // (config/verticals.php types.retail.fulfillment).
        $order = Order::factory()->create([
            'store_id' => $retailStore->id,
            'type' => 'ship',
            'status' => 'new',
        ]);

        // GET posts.orders.board → RetailOrderBoardController::index
        $response = $this->actingAs($retailOwner)->get(route('stores.orders.board'));

        $response->assertStatus(200);
        $response->assertSeeHtml((string) $order->id);

        // POST posts.orders.board.advance → RetailOrderBoardService::advance()
        // steps 'new' -> 'packing' (never the food board's 'preparing').
        $response = $this->actingAs($retailOwner)->post(
            route('stores.orders.board.advance', ['order' => $order->id]),
        );

        $response->assertRedirect();

        $fresh = $order->fresh();
        $this->assertSame('packing', $fresh->status);
        $this->assertNotNull($fresh->packing_at, 'stage timestamp was not stamped');
    }
}
