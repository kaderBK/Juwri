<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Loyalty\Models\CouponRedemption;
use Juwri\Modules\Ordering\Enums\OrderStatus;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Ordering\Models\OrderRefundItem;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Juwri\Modules\Storefront\Database\Seeders\DemoActivitySeeder;
use Juwri\Modules\Storefront\Database\Seeders\ThemePackDemoSeeder;
use Tests\TestCase;

/**
 * J62-1 (job 4): the demo's activity layer — the customers, the orders in every state,
 * the loyalty ledger, the coupon redemption and the wallet balance.
 *
 * The demo is idempotent: a second run must not duplicate a single row, and it must not
 * move a balance twice.
 */
class DemoActivitySeederTest extends TestCase
{
    use RefreshDatabase;

    /** The demo's five states — the four status values plus the refunded one. */
    private const STATES = ['new', 'preparing', 'ready', 'delivered'];

    public function test_it_seeds_the_activity_idempotently(): void
    {
        $this->seedDemo();
        $this->seed(DemoActivitySeeder::class);

        $before = $this->counts();
        $this->seed(DemoActivitySeeder::class);
        $after = $this->counts();

        $this->assertSame($before, $after, 'a second run must not duplicate a single row');
    }

    public function test_the_restaurant_store_carries_every_demo_state(): void
    {
        $this->seedDemo();
        $this->seed(DemoActivitySeeder::class);

        $rid = (int) Store::where('slug', 'themepack-demo')->value('id');
        $statuses = Order::where('store_id', $rid)
            ->where('track_token', 'like', 'demo-owner-%')
            ->pluck('status')
            ->unique()
            ->all();

        foreach (self::STATES as $state) {
            $this->assertContains($state, $statuses, "the restaurant store has a {$state} order");
        }

        $this->assertContains(
            OrderStatus::New->value,
            $statuses,
            'the status vocabulary is the real OrderStatus enum'
        );
    }

    public function test_the_refunded_order_carries_its_refund_rows(): void
    {
        $this->seedDemo();
        $this->seed(DemoActivitySeeder::class);

        $rid = (int) Store::where('slug', 'themepack-demo')->value('id');
        $refunded = Order::where('store_id', $rid)
            ->where('refund_request_status', 'approved')
            ->firstOrFail();

        $this->assertSame(1, (int) $refunded->is_paid, 'the refunded order was paid before the money went back');
        $this->assertSame('original_payment', (string) $refunded->refund_method);
        $this->assertGreaterThan(
            0,
            OrderAdjustment::where('order_id', $refunded->id)->where('type', 'refund')->count(),
            'the refunded order has its refund adjustment'
        );
        $this->assertGreaterThan(
            0,
            OrderRefundItem::where('order_id', $refunded->id)->count(),
            'the refunded order has its refund lines'
        );
        $this->assertSame(
            (int) $refunded->grand_total_minor,
            (int) OrderAdjustment::where('order_id', $refunded->id)->where('type', 'refund')->value('amount_minor'),
            'the refund books the whole order'
        );
    }

    public function test_the_orders_carry_lines_and_money_in_minor_units(): void
    {
        $this->seedDemo();
        $this->seed(DemoActivitySeeder::class);

        $rid = (int) Store::where('slug', 'themepack-demo')->value('id');
        $orders = Order::where('store_id', $rid)->where('track_token', 'like', 'demo-owner-%')->get();

        $this->assertGreaterThan(0, $orders->count(), 'the restaurant store has demo orders');

        foreach ($orders as $order) {
            $this->assertGreaterThan(0, OrderItem::where('order_id', $order->id)->count(), "order {$order->id} has lines");
            $this->assertGreaterThan(0, (int) $order->subtotal_minor, "order {$order->id} has a subtotal");
            $this->assertGreaterThan(0, (int) $order->grand_total_minor, "order {$order->id} has a grand total");
            $this->assertSame('SAR', (string) $order->currency_code, "order {$order->id} is priced in SAR");
            $this->assertGreaterThanOrEqual(
                (int) $order->subtotal_minor,
                (int) $order->grand_total_minor,
                "order {$order->id} adds up"
            );
        }

        $this->assertGreaterThan(
            0,
            OrderItemExtra::whereIn('order_item_id', OrderItem::whereIn('order_id', $orders->pluck('id'))->pluck('id'))->count(),
            'the demo orders carry modifiers'
        );
    }

    public function test_the_loyalty_balances_match_their_ledger(): void
    {
        $this->seedDemo();
        $this->seed(DemoActivitySeeder::class);

        $rid = (int) Store::where('slug', 'themepack-demo')->value('id');
        $accounts = LoyaltyAccount::where('store_id', $rid)->get();

        $this->assertGreaterThanOrEqual(3, $accounts->count(), 'three demo customers carry a loyalty account');

        foreach ($accounts as $account) {
            $sum = (int) LoyaltyLedger::where('loyalty_account_id', $account->id)->sum('delta');
            $this->assertSame($sum, (int) $account->balance, "account {$account->id} equals the sum of its entries");
        }

        $this->assertGreaterThan(0, LoyaltyLedger::where('type', 'earn')->count(), 'the demo customers earned points');
        $this->assertGreaterThan(0, LoyaltyLedger::where('type', 'redeem')->count(), 'one of them redeemed points');
    }

    public function test_the_coupon_is_redeemed_on_a_delivered_order(): void
    {
        $this->seedDemo();
        $this->seed(DemoActivitySeeder::class);

        $rid = (int) Store::where('slug', 'themepack-demo')->value('id');
        $redemption = CouponRedemption::where('store_id', $rid)->firstOrFail();

        $order = Order::where('id', $redemption->order_id)->firstOrFail();
        $this->assertSame(OrderStatus::Delivered->value, (string) $order->status, 'the coupon sits on a delivered order');
        $this->assertGreaterThan(0, (int) $order->discount_amount_minor, 'the order carries the discount');
        $this->assertNotNull($order->coupon_id, 'the order carries the coupon');
    }

    public function test_the_demo_customer_carries_a_hundred_riyal_of_store_credit(): void
    {
        $this->seedDemo();
        $this->seed(DemoActivitySeeder::class);

        $rid = (int) Store::where('slug', 'themepack-demo')->value('id');
        $customer = Customer::where('store_id', $rid)->where('phone', '966500990001')->firstOrFail();

        $this->assertSame(100.00, round(CustomerWalletService::balanceFor($customer), 2));
    }

    /** The demo the activity lands on: the restaurant store plus the demo catalogue. */
    private function seedDemo(): void
    {
        $this->seed(ThemePackDemoSeeder::class);

        foreach (['DemoAccountsSeeder', 'DemoCatalogSeeder'] as $name) {
            $class = 'Juwri\\Modules\\Storefront\\Database\\Seeders\\'.$name;
            if (class_exists($class)) {
                $this->seed($class);
            }
        }
    }

    /** Every row the demo activity owns — the second run must not move any of them. */
    private function counts(): array
    {
        return [
            'orders' => Order::count(),
            'order_items' => OrderItem::count(),
            'order_item_extras' => OrderItemExtra::count(),
            'order_adjustments' => OrderAdjustment::count(),
            'order_refund_items' => OrderRefundItem::count(),
            'customers' => Customer::count(),
            'loyalty_accounts' => LoyaltyAccount::count(),
            'loyalty_ledger' => LoyaltyLedger::count(),
            'coupon_redemptions' => CouponRedemption::count(),
            'ledger_entries' => LedgerEntry::count(),
        ];
    }
}
