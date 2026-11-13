<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Mutation;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\CustomerWalletService;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Orders\OrderPricing;
use Juwri\Modules\Ordering\Services\Tax\TaxSnapshot;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Mutation-killer coverage for OrderPricing + TaxSnapshot gaps:
 *
 *  A. OrderPricing::applyCouponAndCredit lines 160-165 — a `free_delivery` coupon waives
 *     the delivery charge a delivery order actually charges (exactly `charge` on top of
 *     any existing discount), adds NOTHING for takeaway, and adds nothing when the order's
 *     deliveryCharge() is 0.
 *  B. Line 180-182 — the wallet request: explicit wallet_amount, omitted, and explicit
 *     null all resolve the exact server-side amount.
 *  C. OrderPricing::redeemLoyalty line 272 early returns (guest, no active program, zero
 *     reward_discount/threshold, under-threshold balance) plus the happy path, and the
 *     feature-not-allowed no-op.
 *  D. OrderPricing::promotionsBlocked line 336 — false when the order has no store.
 *  E. TaxSnapshot::stamp on an unsaved order (lines 31-33) is a no-op; TaxSnapshot::compute
 *     line 85 (empty when the subtotal is 0) and the exact percentage / fixed lines.
 */
class OrderPricingGapsMutationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    /**
     * Owner + store + a menu item priced 10.00, in $currency (options applied too).
     *
     * @param  array<string, string>  $options
     * @return array{0: User, 1: Store, 2: Menu}
     */
    private function seedStore(string $currency = 'USD', array $options = []): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
        if ($currency !== 'USD') {
            StoreOption::updateStoreOption($r->id, 'currency_code', $currency);
        }
        foreach ($options as $key => $value) {
            StoreOption::updateStoreOption($r->id, $key, $value);
        }
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'X', 'price' => 10, 'active' => '1']);

        return [$owner, $r, $menu];
    }

    /** A persisted order with 3×10 = 30.00 subtotal, plus the delivery charge overrides. */
    private function makeOrder(Store $r, Menu $menu, string $type = 'delivery', array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'store_id' => $r->id, 'type' => $type, 'status' => 'pending',
        ], $overrides));
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 3, 'variation' => 0]);

        return $order->fresh();
    }

    /** @return array{0: Order, 1: Customer} order total = 30 in $currency */
    private function walletScenario(string $currency, float $credit, string $type = 'takeaway'): array
    {
        [, $r, $menu] = $this->seedStore($currency);
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        $order = $this->makeOrder($r, $menu, $type);
        if ($credit > 0) {
            CustomerWalletService::credit($customer, $credit, 'seed', 'seed:'.$customer->id);
        }

        return [$order->fresh(), $customer];
    }

    /** An owned coupon scoped to this owner's food orders. */
    private function coupon(User $owner, string $code, string $type, float $pct = 0): Coupon
    {
        return Coupon::create([
            'code' => $code, 'percentage' => $pct, 'limit' => 99, 'used' => 0,
            'user_id' => $owner->id, 'coupon_type' => $type, 'issuer_scope' => 'owner',
            'applies_to' => 'food_order', 'expiry_at' => now()->addMonth(),
        ]);
    }

    /**
     * Store + customer (+ optionally seeded loyalty points) with the loyalty feature on/off.
     *
     * @return array{0: Store, 1: Customer, 2: User}
     */
    private function loyaltyBase(bool $entitled, int $seedPoints = 0): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'allow_ordering' => 1]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        $customer = Customer::create(['name' => 'Ada', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        if ($seedPoints > 0) {
            LoyaltyAccount::forCustomer($customer->id, $r->id)->earn(999000 + rand(1, 9999), $seedPoints, 'seed');
        }

        config(['settings.admin_allow_loyalty' => $entitled]);
        if ($entitled) {
            EntitlementWriter::grant($owner, 'loyalty', 'admin', null, '1', null, 'test');
        }
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        return [$r, $customer, $owner];
    }

    private function loyaltyProgram(Store $r, float $rewardDiscount = 4.0, int $threshold = 10, bool $active = true): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'store_id' => $r->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => $threshold, 'reward_description' => 'Free side', 'reward_discount' => $rewardDiscount,
            'active' => $active,
        ]);
    }

    // ------------------------------------------------------------------- A · free delivery

    /**
     * A pure free-delivery coupon carries no subtotal discount (CouponService gives 0), so the
     * SAME coupon must book the delivery charge the delivery order actually charges: a 5.00
     * KWD delivery charge lands in discount_amount as exactly 0 + 5.00 = 5.00, and the
     * authoritative total (subtotal + delivery − discount) is exactly the 30.00 items subtotal.
     */
    public function test_free_delivery_coupon_waives_exactly_the_delivery_charge(): void
    {
        [$owner, $r, $menu] = $this->seedStore('KWD', ['restaurant_delivery_charge' => '5.00']);
        $coupon = $this->coupon($owner, 'FREEDEL', 'free_delivery');
        $order = $this->makeOrder($r, $menu, 'delivery');

        $out = OrderPricing::applyCouponAndCredit($order, [
            'coupon_code' => $coupon->code, 'owner_id' => $owner->id, 'subtotal' => 30.0,
        ]);

        $this->assertSame(0.0, $out['discount'], 'a free-delivery coupon is not a subtotal discount');
        $this->assertTrue($out['free_delivery']);
        // line 163: discount_amount = existing (0) + coupon (0) + delivery charge (5.00).
        $this->assertEqualsWithDelta(5.0, (float) $order->fresh()->discount_amount, 0.0);
        // grand = subtotal (30.00) + delivery (5.00) − discount (5.00) = 30.00.
        $money = $order->fresh()->load('items.menu')->computeMoney();
        $this->assertEqualsWithDelta(30.0, $money['subtotal'], 0.0);
        $this->assertEqualsWithDelta(30.0, $money['grand'], 0.0);
        $this->assertEqualsWithDelta(30.0, (float) $order->fresh()->total, 0.0);
    }

    /**
     * The free-delivery waiver STACKS on top of an existing discount (line 163 ADDS the waiver
     * to the discount already recorded): a 20% percent coupon first (6.00 of 30.00) then a
     * free-delivery coupon on the same order yields exactly 6.00 + 5.00 = 11.00, and the total
     * is exactly 30.00 + 5.00 − 11.00 = 24.00.
     */
    public function test_free_delivery_waiver_stacks_on_a_percent_coupon_discount(): void
    {
        [$owner, $r, $menu] = $this->seedStore('KWD', ['restaurant_delivery_charge' => '5.00']);
        $percent = $this->coupon($owner, 'PCT20', 'percentage', 20);
        $free = $this->coupon($owner, 'FREEDEL', 'free_delivery');
        $order = $this->makeOrder($r, $menu, 'delivery');

        OrderPricing::applyCouponAndCredit($order, [
            'coupon_code' => $percent->code, 'owner_id' => $owner->id, 'subtotal' => 30.0,
        ]);
        $this->assertEqualsWithDelta(6.0, (float) $order->fresh()->discount_amount, 0.0);

        // Let a second coupon path run against the same order (its first coupon is spent).
        Order::whereKey($order->id)->update(['coupon_id' => null]);
        $order = $order->fresh();
        $out = OrderPricing::applyCouponAndCredit($order, [
            'coupon_code' => $free->code, 'owner_id' => $owner->id, 'subtotal' => 30.0,
        ]);

        $this->assertSame(0.0, $out['discount']);
        $this->assertTrue($out['free_delivery']);
        $this->assertEqualsWithDelta(11.0, (float) $order->fresh()->discount_amount, 0.0);
        $money = $order->fresh()->load('items.menu')->computeMoney();
        $this->assertEqualsWithDelta(24.0, $money['grand'], 0.0);
        $this->assertEqualsWithDelta(24.0, (float) $order->fresh()->total, 0.0);
    }

    /** A free-delivery coupon on a takeaway order adds NOTHING (line 159's type guard). */
    public function test_free_delivery_coupon_on_takeaway_adds_nothing(): void
    {
        [$owner, $r, $menu] = $this->seedStore('USD', ['restaurant_delivery_charge' => '5.00']);
        $coupon = $this->coupon($owner, 'FREEDEL', 'free_delivery');
        $order = $this->makeOrder($r, $menu, 'takeaway');

        $out = OrderPricing::applyCouponAndCredit($order, [
            'coupon_code' => $coupon->code, 'owner_id' => $owner->id, 'subtotal' => 30.0,
        ]);

        $this->assertTrue($out['free_delivery']);
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->discount_amount, 0.0, 'takeaway: no delivery fee to waive');
        $this->assertEqualsWithDelta(30.0, (float) $order->fresh()->total, 0.0);
    }

    /**
     * A free-delivery coupon on a delivery order whose deliveryCharge() is 0 adds nothing —
     * both when the store's charge is 0 and when the order quotes a 0 delivery fee
     * (delivery_fee_minor = 0). The discount must stay exactly 0.00 (kills `> 0` -> `>= 0`).
     */
    public function test_free_delivery_coupon_with_zero_delivery_charge_adds_nothing(): void
    {
        [$owner, $r, $menu] = $this->seedStore('USD', ['restaurant_delivery_charge' => '0']);
        $coupon = $this->coupon($owner, 'FREEDEL', 'free_delivery');
        $storeFeeZero = $this->makeOrder($r, $menu, 'delivery');
        $quotedFeeZero = $this->makeOrder($r, $menu, 'delivery', ['delivery_fee_minor' => 0]);

        foreach (['store charge 0' => $storeFeeZero, 'quoted fee 0' => $quotedFeeZero] as $label => $order) {
            $out = OrderPricing::applyCouponAndCredit($order, [
                'coupon_code' => $coupon->code, 'owner_id' => $owner->id, 'subtotal' => 30.0,
            ]);
            $this->assertTrue($out['free_delivery'], $label);
            $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->discount_amount, 0.0, $label);
            $this->assertEqualsWithDelta(30.0, (float) $order->fresh()->total, 0.0, $label);
        }
    }

    /** The waiver rounds to the order currency's exponent: a KWD 1.125 charge stays 1.125. */
    public function test_free_delivery_waiver_keeps_three_decimal_charge(): void
    {
        [$owner, $r, $menu] = $this->seedStore('KWD', ['restaurant_delivery_charge' => '1.125']);
        $coupon = $this->coupon($owner, 'FREEDEL', 'free_delivery');
        $order = $this->makeOrder($r, $menu, 'delivery');

        OrderPricing::applyCouponAndCredit($order, [
            'coupon_code' => $coupon->code, 'owner_id' => $owner->id, 'subtotal' => 30.0,
        ]);

        // A 2-decimal round would give 1.13; the KWD fils exponent must give exactly 1.125.
        $this->assertEqualsWithDelta(1.125, (float) $order->fresh()->discount_amount, 0.0);
        $money = $order->fresh()->load('items.menu')->computeMoney();
        $this->assertEqualsWithDelta(30.0, $money['grand'], 0.0);
    }

    // -------------------------------------------------------------------- B · wallet

    /** An explicit wallet_amount applies exactly that much (line 181's explicit branch). */
    public function test_wallet_amount_is_applied_exactly(): void
    {
        [$order, $customer] = $this->walletScenario('EUR', 20.0); // total 30, balance 20

        $out = OrderPricing::applyCouponAndCredit($order, [
            'owner_id' => 0, 'use_wallet' => true, 'customer_id' => $customer->id, 'wallet_amount' => 7.5,
        ]);

        $this->assertEqualsWithDelta(7.5, $out['wallet_applied'], 0.0);
        $this->assertEqualsWithDelta(7.5, (float) $order->fresh()->wallet_applied, 0.0);
        $this->assertEqualsWithDelta(12.5, CustomerWalletService::balanceFor($customer->fresh()), 0.0);
    }

    /** A wallet_amount omitted falls back to the full balance (capped at the total). */
    public function test_wallet_omitted_amount_applies_min_balance_total(): void
    {
        [$order, $customer] = $this->walletScenario('EUR', 20.0); // total 30, balance 20

        $out = OrderPricing::applyCouponAndCredit($order, [
            'owner_id' => 0, 'use_wallet' => true, 'customer_id' => $customer->id,
        ]);

        $this->assertEqualsWithDelta(20.0, $out['wallet_applied'], 0.0);
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer->fresh()), 0.0);
        $this->assertEqualsWithDelta(10.0, (float) $order->fresh()->amount_due, 0.0);
    }

    /** An explicit null wallet_amount also falls back to the full balance (line 180's null check). */
    public function test_wallet_null_amount_falls_back_to_full_balance(): void
    {
        [$order, $customer] = $this->walletScenario('KWD', 20.0); // total 30, balance 20

        $out = OrderPricing::applyCouponAndCredit($order, [
            'owner_id' => 0, 'use_wallet' => true, 'customer_id' => $customer->id, 'wallet_amount' => null,
        ]);

        $this->assertEqualsWithDelta(20.0, $out['wallet_applied'], 0.0);
        $this->assertEqualsWithDelta(20.0, (float) $order->fresh()->wallet_applied, 0.0);
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer->fresh()), 0.0);
    }

    // -------------------------------------------------------------------- C · loyalty

    /** (i) A guest order (no customer_id) never redeems — the early return is exact. */
    public function test_redeem_loyalty_guest_order_is_a_noop(): void
    {
        [$r, , $owner] = $this->loyaltyBase(true);
        $this->loyaltyProgram($r, 4.0, 10);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending']);

        $out = OrderPricing::redeemLoyalty($order, (int) $r->id);

        $this->assertSame(['redeemed' => 0.0, 'denied_reason' => null], $out);
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->discount_amount, 0.0);
    }

    /** (ii) A customer with no active LoyaltyProgram for the store — the early return is exact. */
    public function test_redeem_loyalty_without_active_program_is_a_noop(): void
    {
        [$r, $customer] = $this->loyaltyBase(true, 25);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customer->id]);

        $out = OrderPricing::redeemLoyalty($order, (int) $r->id);

        $this->assertSame(['redeemed' => 0.0, 'denied_reason' => null], $out);
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->discount_amount, 0.0);
        $this->assertSame(25, (int) LoyaltyAccount::forCustomer($customer->id, (int) $r->id)->balance, 'no points spent');
    }

    /** (iii) A program with reward_discount 0 or reward_threshold 0 redeems nothing. */
    public function test_redeem_loyalty_zero_reward_or_threshold_is_a_noop(): void
    {
        // The loyalty_programs table is UNIQUE on store_id, so the two zero-config programs
        // need two stores.
        [$rA, $customerA] = $this->loyaltyBase(true, 25);
        $this->loyaltyProgram($rA, 0.0, 10);
        $noRewardOrder = Order::create(['store_id' => $rA->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customerA->id]);
        $outA = OrderPricing::redeemLoyalty($noRewardOrder, (int) $rA->id);
        $this->assertSame(['redeemed' => 0.0, 'denied_reason' => null], $outA);
        $this->assertEqualsWithDelta(0.0, (float) $noRewardOrder->fresh()->discount_amount, 0.0);

        [$rB, $customerB] = $this->loyaltyBase(true, 25);
        $this->loyaltyProgram($rB, 4.0, 0);
        $noThresholdOrder = Order::create(['store_id' => $rB->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customerB->id]);
        $outB = OrderPricing::redeemLoyalty($noThresholdOrder, (int) $rB->id);
        $this->assertSame(['redeemed' => 0.0, 'denied_reason' => null], $outB);
        $this->assertEqualsWithDelta(0.0, (float) $noThresholdOrder->fresh()->discount_amount, 0.0);
    }

    /** (iv) A balance below the threshold redeems nothing and leaves the discount untouched. */
    public function test_redeem_loyalty_below_threshold_balance_is_a_noop(): void
    {
        [$r, $customer] = $this->loyaltyBase(true, 3); // 3 < threshold 10
        $this->loyaltyProgram($r, 4.0, 10);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customer->id]);
        $order->discount_amount = 1.5;
        $order->save();

        $out = OrderPricing::redeemLoyalty($order, (int) $r->id);

        $this->assertSame(['redeemed' => 0.0, 'denied_reason' => null], $out);
        $this->assertEqualsWithDelta(1.5, (float) $order->fresh()->discount_amount, 0.0, 'discount unchanged');
        $this->assertSame(3, (int) LoyaltyAccount::forCustomer($customer->id, (int) $r->id)->balance, 'no points spent');
    }

    /** (v) The happy path: threshold 10, balance 25, reward 4.00 → redeemed 4.0, stack +1.50 → 5.50, balance 15. */
    public function test_redeem_loyalty_happy_path_redeems_exact_reward(): void
    {
        [$r, $customer] = $this->loyaltyBase(true, 25);
        $this->loyaltyProgram($r, 4.0, 10);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customer->id]);
        $order->discount_amount = 1.5;
        $order->save();

        $out = OrderPricing::redeemLoyalty($order, (int) $r->id);

        $this->assertSame(['redeemed' => 4.0, 'denied_reason' => null], $out);
        $this->assertEqualsWithDelta(5.5, (float) $order->fresh()->discount_amount, 0.0, '1.50 pre-existing + 4.00 reward');
        $this->assertSame(15, (int) LoyaltyAccount::forCustomer($customer->id, (int) $r->id)->balance, '25 − 10 threshold');
    }

    /** The feature gate: a store NOT entitled to loyalty redeems nothing, whatever its program. */
    public function test_redeem_loyalty_feature_not_allowed_is_a_noop(): void
    {
        [$r, $customer] = $this->loyaltyBase(false, 25);
        $this->loyaltyProgram($r, 4.0, 10);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customer->id]);

        $this->assertFalse(FeatureAccess::storeAllows((int) $r->id, 'loyalty'));
        $out = OrderPricing::redeemLoyalty($order, (int) $r->id);

        $this->assertSame(['redeemed' => 0.0, 'denied_reason' => null], $out);
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->discount_amount, 0.0);
        $this->assertSame(25, (int) LoyaltyAccount::forCustomer($customer->id, (int) $r->id)->balance, 'no points spent');
    }

    // ---------------------------------------------------------------------- D · no store

    /**
     * promotionsBlocked() line 336 returns false when the order has no store — orders.store_id
     * is nullable, so order is created store-less and the coupon path must run (a blocked order
     * would come back claim_denied and never see the discount).
     */
    public function test_promotions_not_blocked_for_an_order_without_a_store(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $coupon = $this->coupon($owner, 'NOSTORE', 'percentage', 20);
        $order = Order::create([
            'store_id' => null, 'type' => 'takeaway', 'status' => 'pending',
        ]);

        $out = OrderPricing::applyCouponAndCredit($order, [
            'coupon_code' => $coupon->code, 'owner_id' => $owner->id, 'subtotal' => 30.0,
        ]);

        $this->assertFalse($out['claim_denied'], 'no store → promotions are not blocked');
        $this->assertEqualsWithDelta(6.0, $out['discount'], 0.0, '20% of 30.00 applied (coupon path ran)');
        $this->assertSame((int) $coupon->id, (int) $order->fresh()->coupon_id, 'coupon was applied, so promotionsBlocked returned false');
    }

    // ------------------------------------------------------------------- E · TaxSnapshot

    /** stamp() on an unsaved order (no key) is a no-op: nothing written, nothing saved. */
    public function test_stamp_unsaved_order_is_a_noop(): void
    {
        $before = Order::count();
        $order = new Order;

        TaxSnapshot::stamp($order); // must not throw

        $this->assertNull($order->getAttribute('tax_breakdown'), 'no attribute was set');
        $this->assertSame($before, Order::count(), 'a mutated stamp() would try to save the unsaved order');
    }

    /** compute() line 85: a store with tax configured but a 0 subtotal yields exactly []. */
    public function test_tax_lines_empty_when_subtotal_is_zero(): void
    {
        [, $r, $menu] = $this->seedStore('USD', ['restaurant_taxx_charge' => '15', 'restaurant_tax_type' => 'percentage']);
        $order = $this->makeOrder($r, $menu, 'takeaway');
        // A persisted order with NO lines (the items above are removed) → subtotal 0.
        OrderItem::query()->where('order_id', $order->id)->delete();

        $this->assertNull($order->fresh()->getAttribute('tax_breakdown'));
        $this->assertSame([], TaxSnapshot::lines($order->fresh()));
        $this->assertSame(0.0, TaxSnapshot::total($order->fresh()));
    }

    /** compute() percentage path: 15% of a 20.00 subtotal is exactly one line, rate 15.0, amount 3.0. */
    public function test_tax_percentage_lines_are_exact(): void
    {
        [, $r, $menu] = $this->seedStore('USD', ['restaurant_taxx_charge' => '15', 'restaurant_tax_type' => 'percentage']);
        $order = $this->makeOrder($r, $menu, 'takeaway'); // 3 × 10 = 30.00
        $order->items()->first()->update(['quantity' => 2]); // subtotal now 20.00

        $lines = TaxSnapshot::lines($order->fresh());

        $this->assertCount(1, $lines);
        $this->assertSame('Tax', $lines[0]['label']);
        $this->assertEqualsWithDelta(15.0, $lines[0]['rate'], 0.0);
        $this->assertEqualsWithDelta(3.0, $lines[0]['amount'], 0.0);
        $this->assertEqualsWithDelta(3.0, TaxSnapshot::total($order->fresh()), 0.0);
    }

    /** compute() fixed path: a fixed 3.50 tax exposes rate 0.0 and the fixed amount. */
    public function test_tax_fixed_lines_are_exact(): void
    {
        [, $r, $menu] = $this->seedStore('USD', ['restaurant_taxx_charge' => '3.50', 'restaurant_tax_type' => 'fixed']);
        $order = $this->makeOrder($r, $menu, 'takeaway'); // subtotal 30.00

        $lines = TaxSnapshot::lines($order->fresh());

        $this->assertCount(1, $lines);
        $this->assertSame('Tax', $lines[0]['label']);
        $this->assertEqualsWithDelta(0.0, $lines[0]['rate'], 0.0, 'a fixed tax has no meaningful percentage');
        $this->assertEqualsWithDelta(3.5, $lines[0]['amount'], 0.0);
        $this->assertEqualsWithDelta(3.5, TaxSnapshot::total($order->fresh()), 0.0);
    }
}
