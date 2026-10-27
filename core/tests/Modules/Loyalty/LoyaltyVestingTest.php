<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * A2 vesting — an order's loyalty points vest on PAYMENT for online-gateway orders, not
 * at placement, so an abandoned "pay online" order never earns. Cash / counter /
 * fully-credit-covered orders (no gateway charge) are committed at placement and still
 * earn there. The online seam is PaymentController::paySuccess, shared by the storefront
 * and the marketplace.
 */
class LoyaltyVestingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Bus::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();

        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** @return array{0: Store, 1: Menu, 2: Customer} item price 20, points earn_rate 10 */
    private function scenario(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'allow_ordering' => 1]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($r->id, 'restaurant_takeaway_order', 1);
        StoreOption::updateStoreOption($r->id, 'allow_order', 1);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains']);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 20, 'active' => 1]);
        LoyaltyProgram::create([
            'store_id' => $r->id, 'type' => 'points', 'earn_rate' => 10,
            'reward_threshold' => 100, 'reward_description' => 'x', 'reward_discount' => 5, 'active' => true,
        ]);
        $customer = Customer::create(['name' => 'Ada', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);

        return [$r, $item, $customer];
    }

    private function orderTxn(Store $r, Menu $item, Customer $customer, int $qty = 3): array
    {
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'unpaid', 'customer_id' => $customer->id, 'track_token' => Str::random(32)]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => $qty, 'variation' => 0]);
        $txn = Transaction::create([
            'product_id' => $order->id, 'store_id' => $r->id, 'transaction_method' => 'order',
            'base_amount' => 20 * $qty, 'amount' => 20 * $qty, 'currency_code' => 'USD',
        ]);

        return [$order->fresh(), $txn->fresh()];
    }

    /** The online seam: paySuccess vests the order's points at payment. */
    public function test_paysuccess_vests_an_online_orders_points(): void
    {
        [$r, $item, $customer] = $this->scenario();
        [$order, $txn] = $this->orderTxn($r, $item, $customer); // total 60

        $this->assertSame(0, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance, 'no earn before payment');

        PaymentController::paySuccess($txn);

        // floor(60 * 10 / 100) = 6.
        $this->assertSame(6, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance);
        $this->assertSame(1, (int) Order::find($order->id)->is_paid);
    }

    /** A duplicate gateway delivery vests once (paySuccess is replay-claimed; earn dedups). */
    public function test_paysuccess_vesting_is_idempotent(): void
    {
        [$r, $item, $customer] = $this->scenario();
        [, $txn] = $this->orderTxn($r, $item, $customer);

        PaymentController::paySuccess($txn);
        PaymentController::paySuccess($txn->fresh()); // replay

        $this->assertSame(6, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance, 'earned once');
    }

    /** A cash storefront order is committed at placement — earns immediately. */
    public function test_cash_storefront_order_earns_at_placement(): void
    {
        [$r, $item, $customer] = $this->scenario();

        $this->actingAs($customer, 'customer')->postJson(route('store.submitOrder', $r), [
            'items' => json_encode([['id' => $item->id, 'quantity' => 3, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Ada', 'phone-number' => $customer->phone, 'ordering-type' => 'takeaway',
            'pay_via' => 'cash', 'total' => '60.00',
        ])->assertOk();

        $this->assertSame(6, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance, 'cash earns now');
    }

    /** A pay-online storefront order does NOT earn at placement (vests at paySuccess). */
    public function test_pay_online_storefront_order_defers_earn(): void
    {
        [$r, $item, $customer] = $this->scenario();

        $this->actingAs($customer, 'customer')->postJson(route('store.submitOrder', $r), [
            'items' => json_encode([['id' => $item->id, 'quantity' => 3, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Ada', 'phone-number' => $customer->phone, 'ordering-type' => 'takeaway',
            'pay_via' => 'pay_online', 'total' => '60.00',
        ])->assertOk();

        $order = Order::where('store_id', $r->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame(0, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance, 'no earn until paid');
    }
}
