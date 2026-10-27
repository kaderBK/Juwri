<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Models\SmsLog;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * DISCOVERY PROBES — customer journeys. Every assertion below states what a real
 * diner is entitled to; a failure is the defect, not the assertion.
 */
class P1ProbeCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Mail::fake();
        Notification::fake();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** @return array{0: Store, 1: Menu, 2: User} */
    private function store(array $options = []): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(6), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'allow_ordering' => 1]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(10)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', 1);
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_order', 1);
        StoreOption::updateStoreOption($store->id, 'allow_order', 1);
        foreach ($options as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        $item = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Burger', 'price' => 12.00, 'active' => 1,
        ]);

        return [$store, $item, $owner];
    }

    private function seedCredit(Customer $customer, float $amount): void
    {
        $acct = CustomerWalletService::accountFor($customer);
        $clr = LedgerService::accountFor('platform', 0, 'platform', $acct->currency);
        LedgerService::post([
            ['account' => $clr, 'amount' => -$amount, 'allow_negative' => true, 'ref' => "seed:{$customer->id}:clr"],
            ['account' => $acct, 'amount' => $amount, 'ref' => "seed:{$customer->id}:usr"],
        ]);
    }

    // ---------------------------------------------------------------- PROBE 1
    /**
     * A `free_delivery` coupon must actually stop the diner paying the delivery
     * charge. Order::getTotalAttribute() is the authoritative amount the store
     * collects (cash) and the amount printed in the confirmation SMS.
     */
    public function test_probe_free_delivery_coupon_removes_delivery_charge_from_order_total(): void
    {
        [$store, $item] = $this->store(['restaurant_delivery_charge' => 5]);

        Coupon::create([
            'code' => 'FREESHIP', 'coupon_type' => 'free_delivery', 'percentage' => 0,
            'limit' => 100, 'used' => 0, 'user_id' => $store->user_id,
        ]);

        $this->postJson(route('store.submitOrder', $store), [
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Ada',
            'phone-number' => '5551234',
            'address' => '10 Downing St',
            'ordering-type' => 'delivery',
            'pay_via' => 'cash',
            'coupon_code' => 'FREESHIP',
        ])->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame('delivery', $order->type);

        // Item 12.00, no tax, delivery waived by the coupon => 12.00 owed.
        $this->assertEqualsWithDelta(
            12.00,
            (float) $order->total,
            0.001,
            'free_delivery coupon accepted but the authoritative order total still bills the 5.00 delivery charge'
        );
    }

    // ---------------------------------------------------------------- PROBE 2
    /**
     * Store credit is a TENDER. When a diner pays part of the bill with credit and
     * settles the rest online, the gateway Transaction must be for what is still
     * due (Order::amount_due), not the full order value.
     */
    public function test_probe_pay_online_transaction_is_amount_due_after_store_credit(): void
    {
        [$store, $item] = $this->store();
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Ada', 'phone' => UniquePhone::next('55-')]);
        $this->seedCredit($customer, 20.00);

        $this->actingAs($customer, 'customer')
            ->postJson(route('store.submitOrder', $store), [
                'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
                'name' => 'Ada',
                'phone-number' => $customer->phone,
                'ordering-type' => 'takeaway',
                'pay_via' => 'pay_online',
                'use_wallet' => '1',
            ])->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order);
        // Credit really was spent.
        $this->assertEqualsWithDelta(12.00, (float) $order->wallet_applied, 0.001, 'credit was debited');
        $this->assertEqualsWithDelta(8.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001, '20 - 12 spent');
        $this->assertEqualsWithDelta(0.0, (float) $order->amount_due, 0.001, 'nothing left to pay');

        $tx = Transaction::where('product_id', $order->id)
            ->where('transaction_method', 'order')->latest('id')->first();
        $this->assertNotNull($tx, 'pay_online creates a gateway transaction');

        $this->assertEqualsWithDelta(
            0.0,
            (float) $tx->amount,
            0.001,
            'gateway is asked to charge the full order value even though store credit already paid it'
        );
    }

    // ---------------------------------------------------------------- PROBE 5
    /**
     * The global, unauthenticated checkout-OTP endpoint takes `store_id`
     * straight from the request body. A caller who has never visited that store —
     * and a store that has not switched the OTP gate on at all — must not be able
     * to spend that merchant's own SMS credits.
     */
    public function test_probe_checkout_otp_send_cannot_bill_an_arbitrary_stores_sms_account(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201),
        ]);

        // Victim store: has Twilio configured for order notifications, and has NOT
        // enabled the OTP checkout gate.
        [$victim] = $this->store();
        Credential::create([
            'store_id' => $victim->id,
            'feature' => 'sms',
            'provider' => 'twilio',
            'active' => true,
            'secrets' => ['twilio_sid' => 'ACvictim', 'twilio_token' => 't0ken', 'twilio_from' => '+15550000'],
        ]);

        /* Refused outright: a store that never switched the OTP gate on can not
           be made to pay for one. (Before the fix this returned 200 and sent.) */
        $this->postJson(route('checkout.otp.send'), [
            'phone' => '+15557654321',
            'store_id' => $victim->id,
        ])->assertStatus(422);

        $log = SmsLog::where('store_id', $victim->id)->latest('id')->first();

        $this->assertNull(
            $log,
            'an anonymous caller billed an SMS to a store that never enabled OTP: '
            .'provider='.($log->provider ?? '-').' status='.($log->status ?? '-')
        );
    }

    // ---------------------------------------------------------------- PROBE 4
    /**
     * A basket can never be worth less than nothing. A store running a "10 off"
     * coupon while its loyalty programme pays a "5 off" reward must not produce a
     * NEGATIVE order on a 12.00 basket — the coupon cap is applied against the
     * subtotal only, ignoring the loyalty discount already on the order.
     */
    public function test_probe_coupon_stacked_on_loyalty_cannot_drive_the_order_total_negative(): void
    {
        [$store, $item] = $this->store();

        LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => 5, 'reward_description' => 'Five off', 'reward_discount' => 5.0,
            'active' => true,
        ]);
        Coupon::create([
            'code' => 'TENOFF', 'coupon_type' => 'fixed', 'percentage' => 10,
            'limit' => 100, 'used' => 0, 'user_id' => $store->user_id,
        ]);

        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Ada', 'phone' => UniquePhone::next('55-')]);
        LoyaltyAccount::forCustomer($customer->id, $store->id)->earn(999001, 10, 'seed');

        $this->actingAs($customer, 'customer')
            ->postJson(route('store.submitOrder', $store), [
                'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
                'name' => 'Ada',
                'phone-number' => $customer->phone,
                'ordering-type' => 'takeaway',
                'pay_via' => 'pay_online',
                'coupon_code' => 'TENOFF',
                'loyalty_redeem' => '1',
            ])->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(15.0, (float) $order->discount_amount, 0.001, 'both discounts stacked');

        $this->assertGreaterThanOrEqual(
            0.0,
            (float) $order->total,
            'order total went negative: 12.00 basket, 10.00 coupon + 5.00 loyalty reward'
        );

        $tx = Transaction::where('product_id', $order->id)
            ->where('transaction_method', 'order')->latest('id')->first();
        $this->assertNotNull($tx);
        $this->assertGreaterThanOrEqual(
            0.0,
            (float) $tx->amount,
            'a negative amount is handed to the payment gateway'
        );
    }

    // ---------------------------------------------------------------- PROBE 3
    /**
     * Same contract for a gift card tender on a pay-online order.
     */
    public function test_probe_pay_online_transaction_is_amount_due_after_gift_card(): void
    {
        [$store, $item] = $this->store();

        $card = GiftCardService::issueManual($store->id, 20.00, []);

        $this->postJson(route('store.submitOrder', $store), [
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Ada',
            'phone-number' => '5551234',
            'ordering-type' => 'takeaway',
            'pay_via' => 'pay_online',
            'gift_card_code' => $card->code,
        ])->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(12.00, (float) $order->gift_card_applied, 0.001, 'gift card was redeemed');
        $this->assertEqualsWithDelta(0.0, (float) $order->amount_due, 0.001);

        $tx = Transaction::where('product_id', $order->id)
            ->where('transaction_method', 'order')->latest('id')->first();
        $this->assertNotNull($tx);
        $this->assertEqualsWithDelta(
            0.0,
            (float) $tx->amount,
            0.001,
            'gateway is asked to charge the full order value even though the gift card already paid it'
        );
    }
}
