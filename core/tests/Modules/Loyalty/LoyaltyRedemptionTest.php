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
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * K3 — loyalty points redemption at checkout.
 *
 * Earn already runs at checkout; this suite proves the missing REDEEM side: a
 * logged-in diner who opts in spends the program's reward_threshold points for the
 * SERVER-derived reward_discount, folded into discount_amount so
 * Order::getTotalAttribute() stays the total authority (no per-theme divergence,
 * 3-decimal-safe). Feature-off ignores the field; an under-threshold balance is a
 * no-op.
 */
class LoyaltyRedemptionTest extends TestCase
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
        Mail::fake();
        Notification::fake();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /**
     * @return array{0: Store, 1: Customer, 2: Menu, 3: LoyaltyProgram, 4: User}
     */
    private function scenario(bool $entitled = true, int $seedPoints = 10, float $rewardDiscount = 5.0, int $threshold = 5): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'allow_ordering' => 1]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', 1);
        StoreOption::updateStoreOption($store->id, 'allow_order', 1);

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        $program = LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => $threshold, 'reward_description' => 'Free side', 'reward_discount' => $rewardDiscount,
            'active' => true,
        ]);

        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Ada', 'phone' => UniquePhone::next('55-')]);
        if ($seedPoints > 0) {
            LoyaltyAccount::forCustomer($customer->id, $store->id)->earn(999000 + rand(1, 9999), $seedPoints, 'seed');
        }

        // Feature entitlement: enable the key globally + grant it to this owner's plan.
        config(['settings.admin_allow_loyalty' => $entitled]);
        if ($entitled) {
            EntitlementWriter::grant($owner, 'loyalty', 'admin', null, '1', null, 'test');
        }
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        return [$store, $customer, $item, $program, $owner];
    }

    private function placeOrder(Store $store, Customer $customer, Menu $item, array $extra = []): void
    {
        $this->actingAs($customer, 'customer')->postJson(route('store.submitOrder', $store), array_merge([
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Ada',
            'phone-number' => $customer->phone,
            'ordering-type' => 'takeaway',
            'pay_via' => 'cash',
            'total' => '12.00',
        ], $extra))->assertOk();
    }

    public function test_applying_points_reduces_the_total_and_debits_the_ledger(): void
    {
        [$store, $customer, $item] = $this->scenario();

        $this->placeOrder($store, $customer, $item, ['loyalty_redeem' => '1']);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order);

        // reward_discount (5) folded into discount_amount → total = 12 - 5 = 7.
        $this->assertEqualsWithDelta(5.0, (float) $order->discount_amount, 0.001, 'discount_amount carries the reward');
        $this->assertEqualsWithDelta(7.0, (float) $order->total, 0.001, 'Order::total is reduced by exactly the reward');

        // Ledger debited by the threshold (5): 10 earned − 5 redeemed = 5, plus the
        // fresh order also EARNS (points type, earn_rate 1% of 7 = 0 here → balance 5).
        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);
        $this->assertSame(5, (int) $account->balance, 'threshold points were debited');
        $this->assertDatabaseHas('loyalty_ledger_entries', [
            'loyalty_account_id' => $account->id,
            'type' => 'redeem',
            'delta' => -5,
        ]);
    }

    public function test_total_matches_the_get_total_attribute_contract(): void
    {
        [$store, $customer, $item] = $this->scenario();

        $this->placeOrder($store, $customer, $item, ['loyalty_redeem' => '1']);
        $order = Order::where('store_id', $store->id)->latest('id')->first();

        // The authoritative accessor (computeMoney) is the ONLY total path: grand =
        // subtotal + charges − discount_amount, tax on the PRE-discount subtotal. The
        // loyalty reward lands only in discount_amount, so the reduction is exactly it.
        $money = $order->fresh()->load('items.menu')->computeMoney();
        $this->assertEqualsWithDelta(12.0, $money['subtotal'], 0.001, 'subtotal is pre-discount');
        $this->assertEqualsWithDelta(0.0, $money['tax'], 0.001, 'no tax configured; tax is on pre-discount subtotal');
        $this->assertEqualsWithDelta(7.0, $money['grand'], 0.001, 'grand = subtotal − reward');
        $this->assertEqualsWithDelta((float) $order->total, $money['grand'], 0.001);
    }

    public function test_standalone_endpoints_gate_on_store_entitlement(): void
    {
        // A store that is NOT entitled (e.g. downgraded) — the program row stays active,
        // so the standalone endpoints must gate on the store's plan, not just `active`.
        [$store, $customer] = $this->scenario(entitled: false, seedPoints: 50, threshold: 5);

        // Balance must not leak program/reward data for a de-entitled store.
        $this->actingAs($customer, 'customer')
            ->getJson(route('publicView.loyalty.balance', $store->id))
            ->assertOk()->assertJson(['active' => false]);

        // A crafted redeem POST must not debit points on a de-entitled store.
        $this->actingAs($customer, 'customer')
            ->postJson(route('publicView.loyalty.redeem', $store->id))
            ->assertStatus(404);

        $this->assertSame(50, (int) LoyaltyAccount::forCustomer($customer->id, $store->id)->balance,
            'no points debited on a de-entitled store');
    }

    public function test_standalone_redeem_still_works_when_entitled(): void
    {
        [$store, $customer] = $this->scenario(entitled: true, seedPoints: 10, threshold: 5);

        $this->actingAs($customer, 'customer')
            ->postJson(route('publicView.loyalty.redeem', $store->id))
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_feature_off_ignores_the_field(): void
    {
        [$store, $customer, $item] = $this->scenario(entitled: false);

        $this->placeOrder($store, $customer, $item, ['loyalty_redeem' => '1']);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertEqualsWithDelta(0.0, (float) $order->discount_amount, 0.001, 'no discount when loyalty is not entitled');
        $this->assertEqualsWithDelta(12.0, (float) $order->total, 0.001);

        // No redeem row written.
        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);
        $this->assertDatabaseMissing('loyalty_ledger_entries', [
            'loyalty_account_id' => $account->id,
            'type' => 'redeem',
        ]);
    }

    public function test_under_threshold_balance_is_a_no_op(): void
    {
        [$store, $customer, $item] = $this->scenario(seedPoints: 3); // < threshold 5

        $this->placeOrder($store, $customer, $item, ['loyalty_redeem' => '1']);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertEqualsWithDelta(0.0, (float) $order->discount_amount, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $order->total, 0.001);

        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);
        $this->assertDatabaseMissing('loyalty_ledger_entries', [
            'loyalty_account_id' => $account->id,
            'type' => 'redeem',
        ]);
    }

    /** The standalone redeem endpoint debits points once even if double-submitted. */
    public function test_standalone_redeem_double_submit_debits_points_once(): void
    {
        [$store, $customer] = $this->scenario(); // 10 points, threshold 5

        $this->actingAs($customer, 'customer')
            ->postJson(route('publicView.loyalty.redeem', $store->id))
            ->assertOk()->assertJson(['success' => true]);

        // Second click within the debounce window is rejected, not applied.
        $this->actingAs($customer, 'customer')
            ->postJson(route('publicView.loyalty.redeem', $store->id))
            ->assertStatus(429);

        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);
        $this->assertSame(5, (int) $account->balance, 'only one redemption debited (10 - 5)');
        $this->assertSame(1, LoyaltyLedger::where('loyalty_account_id', $account->id)
            ->where('type', 'redeem')->count(), 'exactly one redeem ledger row');
    }

    public function test_not_opting_in_leaves_the_total_untouched(): void
    {
        [$store, $customer, $item] = $this->scenario();

        $this->placeOrder($store, $customer, $item); // no loyalty_redeem field

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertEqualsWithDelta(0.0, (float) $order->discount_amount, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $order->total, 0.001);
    }

    public function test_full_reversal_restores_the_points_redeemed_at_checkout(): void
    {
        // The gap: cancel/void/full-refund clawed back EARNED points but never gave back
        // the points the diner SPENT on the reward — the reward discount dies with the
        // order, so those points were forfeited. Now restored via redeemed_order_id.
        [$store, $customer, $item] = $this->scenario(); // seed 10, threshold 5
        $this->placeOrder($store, $customer, $item, ['loyalty_redeem' => '1']);
        $order = Order::where('store_id', $store->id)->latest('id')->first();

        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);
        $this->assertSame(5, (int) $account->fresh()->balance, '5 spent at checkout (10 − 5)');

        PosService::void($order->fresh(), null, 'test void');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, (int) $account->fresh()->balance, 'redeemed points restored on a full reversal');
        $this->assertDatabaseHas('loyalty_ledger_entries', [
            'redeemed_order_id' => $order->id,
            'type' => 'adjustment',
            'delta' => 5,
        ]);
    }

    /** P6-205: a staff-PWA cancel restores redeemed points once, even when retried/replayed. */
    public function test_staff_pwa_cancel_restores_redeemed_points_once(): void
    {
        [$store, $customer, $item, , $owner] = $this->scenario();
        $this->placeOrder($store, $customer, $item, ['loyalty_redeem' => '1']);
        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);
        $this->assertSame(5, (int) $account->fresh()->balance);

        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'M', 'role' => 'manager',
            'email' => 'm'.Str::random(6).'@ex.com', 'password' => Hash::make('secret'), 'active' => 1,
        ]);
        foreach (['op-l1', 'op-l1', 'op-l2'] as $op) {
            $this->actingAs($staff, 'staff')
                ->postJson("/staff-app/orders/{$order->id}/status", ['status' => 'cancelled', 'client_op_id' => $op])
                ->assertOk();
        }

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, (int) $account->fresh()->balance, 'redeemed points restored exactly once');
    }

    public function test_partial_refund_keeps_the_redeemed_points_spent(): void
    {
        // A partial refund does NOT reverse the reward discount, so the points stay spent.
        [$store, $customer, $item] = $this->scenario();
        $this->placeOrder($store, $customer, $item, ['loyalty_redeem' => '1']);
        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $order->is_paid = 1;
        $order->save();
        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);

        // Order total is 7 (12 − 5 reward), no tenders → collected 7. Refund only 3.
        PosService::refund($order->fresh(), null, 3.0, 'cash', 'partial');

        $this->assertSame(5, (int) $account->fresh()->balance, 'a partial refund leaves the redeemed points spent');
    }

    /**
     * A2 — the reward-redeem race. With a balance of 2x the threshold the balance
     * guard alone cannot stop a second redemption, so only the debounce can. The
     * OLD flow read the recency gate in the controller, OUTSIDE the debit, so two
     * concurrent requests both passed it and both called redeem() → 10 − 5 − 5 = 0,
     * TWO reward rows for one intended redemption. The fix folds the debounce and
     * the debit into one row-locked step (redeemForReward), so the second redeem
     * observes the first's committed row and returns 'recent' instead of debiting.
     * This test fails on the pre-fix code (no atomic method; redeem() double-debits).
     */
    public function test_reward_redeem_is_atomic_and_never_double_debits(): void
    {
        [$store, $customer] = $this->scenario(seedPoints: 10, threshold: 5); // balance 10 = 2x threshold
        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);

        // Two "concurrent" reward redemptions. Only the first may debit.
        $first = $account->redeemForReward(5, 'A');
        $second = LoyaltyAccount::forCustomer($customer->id, $store->id)->redeemForReward(5, 'B');

        $this->assertSame('ok', $first);
        $this->assertSame('recent', $second, 'the second concurrent reward redeem is rejected, not double-debited');

        $account->refresh();
        $this->assertSame(5, (int) $account->balance, 'exactly one redemption debited (10 − 5)');
        $this->assertSame(1, LoyaltyLedger::where('loyalty_account_id', $account->id)
            ->where('type', 'redeem')->count(), 'exactly one redeem ledger row');
    }

    /** redeemForReward reports an insufficient balance instead of going negative. */
    public function test_reward_redeem_rejects_an_insufficient_balance(): void
    {
        [$store, $customer] = $this->scenario(seedPoints: 3, threshold: 5); // below threshold
        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);

        $this->assertSame('insufficient', $account->redeemForReward(5));
        $this->assertSame(3, (int) $account->fresh()->balance, 'balance untouched');
        $this->assertSame(0, LoyaltyLedger::where('loyalty_account_id', $account->id)
            ->where('type', 'redeem')->count());
    }

    /** P6-253 (J46): the negative-balance banner and the refund history line, shown only when due. */
    public function test_balance_endpoint_shows_the_debt_banner_only_when_negative(): void
    {
        [$store, $customer] = $this->scenario(seedPoints: 0, threshold: 5);
        $acct = LoyaltyAccount::forCustomer($customer->id, $store->id);
        $acct->earn(777001, 3, 'Order #777001');

        $this->actingAs($customer, 'customer')->getJson(route('publicView.loyalty.balance', $store->id))
            ->assertOk()->assertJson(['negative' => false, 'banner' => null, 'balance' => 3]);

        $acct->redeem(3, 'spent');
        $acct->reverseEarn(777001);

        $res = $this->actingAs($customer, 'customer')->getJson(route('publicView.loyalty.balance', $store->id))
            ->assertOk()
            ->assertJson([
                'negative' => true,
                'balance' => -3,
                'banner' => 'Your balance is -3 because points from a refunded order were already used. Earn 8 more points to redeem again.',
            ]);
        $texts = collect($res->json('ledger'))->pluck('text')->filter()->values()->all();
        $this->assertSame(['Order #777001 refunded: -3 points'], $texts);

        // Earning back above zero removes the banner.
        $acct->earn(777002, 10, 'Order #777002');
        $this->actingAs($customer, 'customer')->getJson(route('publicView.loyalty.balance', $store->id))
            ->assertOk()->assertJson(['negative' => false, 'banner' => null, 'balance' => 7]);
    }
}
