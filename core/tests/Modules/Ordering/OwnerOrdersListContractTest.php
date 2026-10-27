<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-09v characterisation of the owner orders screen
 * (User/Concerns/ManagesOrders::orders): which store it opens, owner-only,
 * store currency applied, the q/status/refunds/from/to filters, the AJAX poll
 * (only unseen rows, rendered) vs the paginated page, rows whose dishes are
 * all gone being hidden, variant labels, price = the canonical total, every
 * listed order marked seen, abandoned unpaid orders purged, and the view data.
 */
class OwnerOrdersListContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['Quick_user_lang_code']);
        parent::tearDown();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 9, 'menu_limit' => 999],
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    /** @return array{0: Store, 1: Menu} */
    private function store(User $owner): array
    {
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'ord-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C']);

        return [$store, Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 10, 'active' => '1'])];
    }

    /** Phone '+000': the id search also matches phone digits, and an order id of 5 / 55 / 555 matched the old '555' (PG ids keep counting across tests). */
    private function order(Store $store, Menu $menu, array $attrs = [], int $qty = 1, ?int $variant = null): Order
    {
        $order = Order::create($attrs + [
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_name' => 'Ann',
            'phone_number' => '+000', 'seen' => 0, 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty, 'variation' => $variant]);

        return $order;
    }

    public function test_store_resolution_and_owner_guard(): void
    {
        $owner = $this->owner();
        [$store] = $this->store($owner);

        $this->actingAs($owner)->get(route('stores.orders'))->assertRedirect(route('stores.orders', $store->id));
        $this->actingAs($owner)->get(route('stores.orders', $store->id))->assertOk()->assertViewIs('owner.orders.index');
        $this->actingAs($this->owner())->get(route('stores.orders', $store->id))->assertNotFound();

        $this->actingAs($this->owner())->get(route('stores.orders'))->assertRedirect(route('stores.create'));
        $this->assertSame('error', session('app_alert_type'));
        $this->assertSame(___('No stores available').' '.___('Add new store'), session('app_alert_message'));
    }

    /**
     * The receipt-print script builds a whole HTML document in a JS string. A literal </head> or </body> in it is
     * rewritten by any middleware that injects before the first/every closing tag (Laravel Boost's browser logger in
     * local dev put a <script> inside the string → "Invalid or unexpected token", ordersPage undefined; found by the
     * P1-12 e2e sweep, b-staff on juw.test). The page must carry exactly one real </head> and </body>.
     */
    public function test_page_has_one_closing_head_and_body_tag(): void
    {
        $owner = $this->owner();
        [$store] = $this->store($owner);

        $html = $this->actingAs($owner)->get(route('stores.orders', $store->id))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '</head>'), 'a literal </head> outside the document head');
        $this->assertSame(1, substr_count($html, '</body>'), 'a literal </body> outside the document body');
    }

    /** The card's total prices the order against its branch (pricingBranch); with lazy loading prevented that was a 500 (P6-53 run 2, role-pages-smoke). */
    public function test_page_lists_branch_orders_without_lazy_loading(): void
    {
        $owner = $this->owner();
        [$store, $menu] = $this->store($owner);
        $branch = Branch::create(['store_id' => $store->id, 'name' => 'Main', 'active' => true]);
        $this->order($store, $menu, ['type' => 'delivery', 'branch_id' => $branch->id]);
        $this->order($store, $menu, ['type' => 'delivery', 'branch_id' => $branch->id]); // lazy-loading violations only fire for a list of two or more

        Model::preventLazyLoading();
        $this->actingAs($owner)->get(route('stores.orders', $store->id))->assertOk();

        // any other list that did not eager-load the branch prices the same way
        foreach (Order::query()->where('store_id', $store->id)->get() as $o) {
            $this->assertSame((int) $branch->id, (int) $o->pricingBranch()?->id);
            $this->assertGreaterThan(0, $o->total);
        }
    }

    public function test_page_lists_filters_prices_and_marks_seen(): void
    {
        $owner = $this->owner();
        [$store, $menu] = $this->store($owner);
        [$other, $otherMenu] = $this->store($this->owner());
        StoreOption::updateStoreOption($store->id, 'currency_code', 'EUR');
        StoreOption::updateStoreOption($store->id, 'currency_sign', '€');

        $size = MenuVariantOption::create(['menu_id' => $menu->id, 'store_id' => $store->id, 'title' => 'Size', 'options' => ['S', 'L']]);
        $gone = MenuVariantOption::create(['menu_id' => $menu->id, 'store_id' => $store->id, 'title' => 'Gone', 'options' => ['x']]);
        $variant = MenuVariant::create(['menu_id' => $menu->id, 'price' => 14, 'options' => [$size->id => 1, $gone->id => 0, 99999 => 0]]);
        $gone->delete();

        $a = $this->order($store, $menu, ['customer_name' => 'Alice'], 2, $variant->id);
        $b = $this->order($store, $menu, ['customer_name' => 'Bob', 'status' => 'completed', 'refund_request_status' => 'requested', 'seen' => 1]);
        $gonePizza = Menu::create(['store_id' => $store->id, 'category_id' => $menu->category_id, 'name' => 'Old', 'price' => 5, 'active' => '1']);
        $dead = $this->order($store, $gonePizza, ['customer_name' => 'Dead']);
        $gonePizza->delete(); // trashed dish: the order has no live line left
        $unpaid = $this->order($store, $menu, ['status' => 'unpaid']);
        $foreign = $this->order($other, $otherMenu);

        $res = $this->actingAs($owner)->get(route('stores.orders', $store->id))->assertOk();
        $this->assertSame('EUR', config('settings.currency_code'));
        $this->assertSame('€', config('settings.currency_sign'));
        $orders = $res->viewData('orders');
        $this->assertSame(50, $orders->perPage());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $orders->pluck('id')->all(), 'unpaid, foreign and dish-less orders are not listed');
        $row = $orders->firstWhere('id', $a->id);
        $this->assertSame(' (L)', $row->items->first()->variant_title);
        $this->assertEquals($a->fresh()->total, $row->price);
        $this->assertSame([1, 1, 0, 0, 0], [(int) $a->fresh()->seen, (int) $b->fresh()->seen, (int) $dead->fresh()->seen, (int) $unpaid->fresh()->seen, (int) $foreign->fresh()->seen]);
        $this->assertNull($res->viewData('focusOrderId'));
        $this->assertNull($res->viewData('activeRefundFilter'));
        $this->assertSame(1, $res->viewData('pendingRefundCount'));
        $this->assertSame([[], []], [$res->viewData('menu_languages'), $res->viewData('default_menu_language')]);
        $this->assertCount(0, $res->viewData('walletTransferClaims'));
        $this->assertTrue($res->viewData('store')->is($store));

        $ids = fn (array $q) => $this->actingAs($owner)->get(route('stores.orders', $store->id).'?'.http_build_query($q))->viewData('orders')->pluck('id')->all();
        $this->assertSame([$a->id], $ids(['q' => 'lic']));
        $this->assertSame([$b->id], $ids(['q' => (string) $b->id]));
        $this->assertSame([$b->id], $ids(['status' => 'completed']));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids(['status' => 'bogus']));
        $this->assertSame([$b->id], $ids(['refunds' => 'requested']));
        $this->assertSame([], $ids(['from' => now()->addDay()->toDateString()]));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids(['to' => now()->toDateString()]));
        $res = $this->actingAs($owner)->get(route('stores.orders', $store->id).'?refunds=requested&order='.$a->id);
        $this->assertSame('requested', $res->viewData('activeRefundFilter'));
        $this->assertSame($a->id, $res->viewData('focusOrderId'));
    }

    public function test_ajax_poll_returns_only_unseen_rows_rendered(): void
    {
        $owner = $this->owner();
        [$store, $menu] = $this->store($owner);
        $seen = $this->order($store, $menu, ['seen' => 1]);
        $fresh = $this->order($store, $menu, ['customer_name' => 'Zed']);
        $combo = Combo::create(['store_id' => $store->id, 'name' => 'Meal', 'price' => 12]);
        $comboOrder = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_name' => 'Cy', 'seen' => 0]);
        OrderItem::create(['order_id' => $comboOrder->id, 'item_id' => null, 'combo_id' => $combo->id, 'quantity' => 1,
            'options' => [['title' => 'Meal', 'selected' => [['option' => 'Meal', 'price' => 12]]]]]);

        $json = $this->actingAs($owner)->getJson(route('stores.orders', $store->id), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJson(['success' => true])->json('orders');
        $this->assertCount(2, $json, 'the unseen menu order and the combo-only order');
        $this->assertStringContainsString('Zed', implode('', $json));
        $this->assertSame([1, 1], [(int) $fresh->fresh()->seen, (int) $comboOrder->fresh()->seen]);

        $this->actingAs($owner)->getJson(route('stores.orders', $store->id), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertExactJson(['success' => true, 'orders' => []]);
        $this->assertNotNull($seen->fresh());
    }

    public function test_abandoned_unpaid_orders_are_purged_and_languages_resolved(): void
    {
        $owner = $this->owner();
        [$store, $menu] = $this->store($owner);
        $old = $this->order($store, $menu, ['status' => 'unpaid']);
        Order::whereKey($old->id)->update(['created_at' => now()->subHours(4)]);
        $recent = $this->order($store, $menu, ['status' => 'unpaid']);
        foreach (['en' => 'English', 'fr' => 'French'] as $code => $name) {
            Language::query()->firstOrCreate(['code' => $code], ['name' => $name, 'direction' => 'ltr', 'active' => 1, 'position' => 1]);
        }
        StoreOption::updateStoreOption($store->id, 'menu_languages', json_encode(['en', 'fr']));
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');
        $_COOKIE['Quick_user_lang_code'] = 'fr';

        $claim = $this->order($store, $menu, ['status' => 'unpaid']);
        $txn = Transaction::create([
            'product_name' => 'Order', 'product_id' => $claim->id, 'store_id' => $store->id, 'base_amount' => 10, 'amount' => 10,
            'currency_code' => 'USD', 'transaction_gatway' => 'wallet_transfer', 'transaction_method' => 'order',
            'status' => Transaction::STATUS_PENDING, 'details' => ['claimed_at' => now()->toIso8601String()],
        ]);

        $res = $this->actingAs($owner)->get(route('stores.orders', $store->id))->assertOk();
        $this->assertNull(Order::find($old->id));
        $this->assertNotNull(Order::find($recent->id));
        $this->assertSame('fr', $res->viewData('default_menu_language')->code);
        $this->assertEqualsCanonicalizing(['en', 'fr'], $res->viewData('menu_languages')->pluck('code')->all());
        $claims = $res->viewData('walletTransferClaims');
        $this->assertSame([$txn->id], $claims->pluck('id')->all());
        $this->assertTrue($claims->first()->claimOrder->is($claim));
    }

    public function test_complete_and_delete_order_answer_success_but_act_only_for_the_owner(): void
    {
        $owner = $this->owner();
        [$store, $menu] = $this->store($owner);
        $order = $this->order($store, $menu);
        $stranger = $this->owner();

        $this->actingAs($stranger)->postJson(route('stores.completeOrder', $order->id))
            ->assertNotFound(); // BL-043: a stranger's request is refused by the owner tenant scope (404)
        $this->assertSame('pending', $order->fresh()->status);

        $this->actingAs($owner)->postJson(route('stores.completeOrder', $order->id))
            ->assertOk()->assertExactJson(['success' => true, 'message' => ___('Updated Successfully')]);
        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertNotNull($order->completed_at);

        $this->actingAs($stranger)->postJson(route('stores.deleteOrder', $order->id))
            ->assertNotFound(); // BL-043: a stranger's request is refused by the owner tenant scope (404)
        $this->assertNotNull(Order::find($order->id));

        $this->actingAs($owner)->postJson(route('stores.deleteOrder', $order->id))
            ->assertOk()->assertExactJson(['success' => true, 'message' => ___('Deleted Successfully')]);
        $this->assertNull(Order::find($order->id));
        $log = ActivityLog::where('action', 'order.deleted')->sole();
        $this->assertEquals([$store->id, ['order_id' => $order->id, 'was_paid' => false, 'staff_id' => null]], [(int) $log->store_id, $log->meta]);
    }
}
