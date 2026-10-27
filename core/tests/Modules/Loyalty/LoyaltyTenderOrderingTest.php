<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\CustomerWalletService;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * A1 — coupon × loyalty × offers stacking, tender-ordering slice.
 *
 * The H15 "unified" storefront pipeline used to apply the loyalty reward AFTER
 * OrderApiService::create() had already sized the wallet / gift-card tenders
 * against the pre-loyalty total. A full-balance wallet therefore capped at the
 * higher total, then the loyalty reward lowered `total` a SECOND time, driving
 * `amount_due` negative (getAmountDueAttribute has no zero floor) and destroying
 * store credit — the customer's wallet was debited more than the order was worth.
 *
 * The fix folds loyalty into discount_amount INSIDE create(), before the tenders,
 * so the wallet caps against the reduced total (and the point debit + discount
 * commit atomically with the order). These tests fail on the pre-fix code:
 * pre-fix create() has no loyalty step, so the reward is never applied and the
 * wallet over-drains.
 */
class LoyaltyTenderOrderingTest extends TestCase
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
        Queue::fake();
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    /** @return array{0: Store, 1: Customer, 2: Menu, 3: User} item price 20, loyalty 100→$5 */
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
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 20, 'active' => 1]);

        LoyaltyProgram::create([
            'store_id' => $r->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => 100, 'reward_description' => 'Free side', 'reward_discount' => 5.0,
            'active' => true,
        ]);

        // Feature entitlement: enable the key globally + grant it to this owner's plan.
        config(['settings.admin_allow_loyalty' => true]);
        EntitlementWriter::grant($owner, 'loyalty', 'admin', null, '1', null, 'test');
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        return [$r, /* customer added by caller */ null, $item, $owner];
    }

    private function customerWith(Store $r, int $points, float $credit): Customer
    {
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        if ($points > 0) {
            LoyaltyAccount::forCustomer($customer->id, $r->id)->earn(990000 + rand(1, 9999), $points, 'seed');
        }
        if ($credit > 0) {
            $acct = CustomerWalletService::accountFor($customer);
            $clr = LedgerService::accountFor('platform', 0, 'platform', $acct->currency);
            LedgerService::post([
                ['account' => $clr, 'amount' => -$credit, 'allow_negative' => true, 'ref' => "seed:{$customer->id}:clr"],
                ['account' => $acct, 'amount' => $credit, 'ref' => "seed:{$customer->id}:usr"],
            ]);
        }

        return $customer;
    }

    /**
     * The money invariant: with a wallet covering the FULL pre-loyalty total, the
     * wallet must cap against the loyalty-REDUCED total, never over-drain. Pre-fix,
     * create() ignored loyalty_redeem, so wallet_applied stayed at 20 (== full total)
     * and the reward was never applied → these assertions fail.
     */
    public function test_loyalty_reward_is_applied_before_wallet_tender_in_create(): void
    {
        [$r, , $item, $owner] = $this->scenario();
        $customer = $this->customerWith($r, points: 100, credit: 20.0); // subtotal 20, credit 20 (full)

        $order = OrderApiService::create((int) $owner->id, (int) $r->id, [
            'type' => 'takeaway',
            'customer_id' => $customer->id,
            'use_wallet' => true,
            'loyalty_redeem' => true,
            'items' => [['item_id' => $item->id, 'quantity' => 1]], // subtotal 20
        ]);

        $order->refresh();

        // Loyalty reward folded into discount_amount → total = 20 − 5 = 15.
        $this->assertEqualsWithDelta(5.0, (float) $order->discount_amount, 0.001, 'reward folded into discount_amount');
        $this->assertEqualsWithDelta(15.0, (float) $order->total, 0.001, 'total reduced by the reward');

        // KEY: wallet caps against the REDUCED total (15), not the pre-loyalty 20.
        $this->assertEqualsWithDelta(15.0, (float) $order->wallet_applied, 0.001, 'wallet sized against loyalty-reduced total');

        // amount_due never goes negative; the leftover credit is preserved (20 − 15 = 5).
        $this->assertEqualsWithDelta(0.0, $order->amount_due, 0.001, 'amount_due is never negative');
        $this->assertGreaterThanOrEqual(0.0, $order->amount_due);
        $this->assertEqualsWithDelta(5.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'only 15 of the 20 credit was spent');

        // The points were debited exactly once (a single redeem row for the threshold).
        $acct = LoyaltyAccount::forCustomer($customer->id, $r->id);
        $this->assertSame(1, LoyaltyLedger::where('loyalty_account_id', $acct->id)->where('type', 'redeem')->count());
        $this->assertDatabaseHas('loyalty_ledger_entries', [
            'loyalty_account_id' => $acct->id, 'type' => 'redeem', 'delta' => -100,
        ]);
    }

    /**
     * End-to-end through the unified storefront pipeline (the config that shipped
     * the bug): a diner paying with full store credit + redeeming points must not
     * over-drain the wallet. Pre-fix the controller applied loyalty after create()
     * → wallet_applied 20 on a 15 order. Post-fix create() owns it → 15.
     */
    public function test_unified_pipeline_does_not_overdrain_wallet_on_loyalty_plus_credit(): void
    {
        [$r, , $item] = $this->scenario();
        StoreOption::updateStoreOption($r->id, 'restaurant_takeaway_order', 1);
        StoreOption::updateStoreOption($r->id, 'allow_order', 1);
        $customer = $this->customerWith($r, points: 100, credit: 20.0);

        // Force the H15 unified pipeline platform-wide for this request.
        config(['settings.storefront_order_pipeline' => 'unified']);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        $this->actingAs($customer, 'customer')->postJson(route('store.submitOrder', $r), [
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'C', 'phone-number' => $customer->phone, 'ordering-type' => 'takeaway',
            'pay_via' => 'cash', 'total' => '20.00',
            'use_wallet' => '1', 'loyalty_redeem' => '1',
        ])->assertOk();

        $order = Order::where('store_id', $r->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(5.0, (float) $order->discount_amount, 0.001, 'reward applied via create()');
        $this->assertEqualsWithDelta(15.0, (float) $order->total, 0.001);
        // The wallet tender must never exceed the order value.
        $this->assertLessThanOrEqual((float) $order->total + 0.001, (float) $order->wallet_applied, 'wallet never over-drains');
        $this->assertGreaterThanOrEqual(0.0, $order->amount_due, 'amount_due never negative');
    }
}
