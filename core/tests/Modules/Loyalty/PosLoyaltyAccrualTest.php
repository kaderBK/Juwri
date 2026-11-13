<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\LoyaltyProgram;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Services\Loyalty\LoyaltyAccrual;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Mail\LoyaltyPointsClawedBack;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Contracts\Sms;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * A2 follow-up — POS loyalty accrual. The register previews "earns ~N" for a selected
 * customer, but only the storefront tail ever credited points, so POS sales advertised
 * an earn and accrued nothing. Points now accrue through the shared seam
 * (LoyaltyAccrual::earnForOrder) on the PAID transition (PosService::settle) — an open
 * tab does not earn until it is settled.
 */
class PosLoyaltyAccrualTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Store, 1: Menu, 2: StaffMember} item price 10 + active program */
    private function scenario(string $type = 'points', int $rate = 10): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'S',
            'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        LoyaltyProgram::create([
            'store_id' => $r->id, 'type' => $type, 'earn_rate' => $rate,
            'reward_threshold' => 100, 'reward_description' => 'Free side', 'reward_discount' => 5, 'active' => true,
        ]);

        return [$r, $item, $staff];
    }

    private function customer(Store $r): Customer
    {
        return Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
    }

    private function posOrder(Store $r, Menu $item, StaffMember $staff, ?int $customerId): Order
    {
        return PosService::createOrder($r, $staff, array_filter([
            'type' => 'takeaway',
            'customer_id' => $customerId,
            'items' => [['item_id' => $item->id, 'quantity' => 3]], // total 30
        ]));
    }

    public function test_settling_a_pos_order_accrues_points_and_an_open_tab_does_not(): void
    {
        [$r, $item, $staff] = $this->scenario(); // points, earn_rate 10
        $customer = $this->customer($r);
        $order = $this->posOrder($r, $item, $staff, $customer->id);

        // Open tab — not settled yet — must not accrue.
        $this->assertSame(0, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance, 'an unpaid tab does not earn');

        PosService::settle($order->fresh(), 'cash', 30.0);

        // floor(30 * 10 / 100) = 3.
        $this->assertSame(3, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance);
        $this->assertDatabaseHas('loyalty_ledger_entries', ['order_id' => $order->id, 'type' => 'earn', 'delta' => 3]);
    }

    public function test_settle_accrual_is_idempotent(): void
    {
        [$r, $item, $staff] = $this->scenario();
        $customer = $this->customer($r);
        $order = $this->posOrder($r, $item, $staff, $customer->id);

        PosService::settle($order->fresh(), 'cash', 30.0);
        PosService::settle($order->fresh(), 'cash', 30.0); // a second settle must not double-earn

        $this->assertSame(1, LoyaltyLedger::where('order_id', $order->id)->where('type', 'earn')->count());
        $this->assertSame(3, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance);
    }

    public function test_walkin_settle_accrues_nothing_and_does_not_crash(): void
    {
        [$r, $item, $staff] = $this->scenario();
        $order = $this->posOrder($r, $item, $staff, null); // no customer

        PosService::settle($order->fresh(), 'cash', 30.0);

        $this->assertSame(0, LoyaltyLedger::whereNotNull('order_id')->where('order_id', $order->id)->count());
    }

    public function test_stamps_program_earns_one_per_settled_order(): void
    {
        [$r, $item, $staff] = $this->scenario('stamps', 1);
        $customer = $this->customer($r);
        $order = $this->posOrder($r, $item, $staff, $customer->id);

        PosService::settle($order->fresh(), 'cash', 30.0);

        $this->assertSame(1, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance, 'stamps: +1 per order');
    }

    /** F3: settling a HELD order applies the deduction hold() deferred (was oversell). */
    public function test_settling_a_held_order_applies_the_deferred_deduction(): void
    {
        [$r, $item, $staff] = $this->scenario();
        $item->update(['track_inventory' => 1, 'stock_qty' => 10]);
        $customer = $this->customer($r);

        $order = $this->posOrder($r, $item, $staff, $customer->id); // 3 × 10; not held → deducts 3 → 7
        $this->assertSame(7, (int) $item->fresh()->stock_qty, 'create deducted 3');

        PosService::hold($order->fresh()); // restores stock (→10) + parks the order
        $this->assertSame(10, (int) $item->fresh()->stock_qty, 'hold returned the stock');

        PosService::settle($order->fresh(), 'cash', 30.0); // held → the deferred deduction must fire

        // Pre-fix settle cleared held_at without deducting → stock stuck at 10 (oversell).
        $this->assertSame(7, (int) $item->fresh()->stock_qty, 'settle applied the deferred deduction');
    }

    /**
     * Idempotency invariant (owner-decision #1): the advanced/simple deduction is guarded
     * by the held_at + stock_restored_at state machine, NOT by a movement-level unique index
     * (which would wrongly reject a legit repeated-ingredient sale row). A full
     * hold → resume → settle cycle must deduct the stock EXACTLY ONCE: resume() deducts,
     * and settle()'s $wasHeld guard (held_at already cleared by resume) must NOT deduct again.
     */
    public function test_resume_then_settle_deducts_stock_exactly_once(): void
    {
        [$r, $item, $staff] = $this->scenario();
        $item->update(['track_inventory' => 1, 'stock_qty' => 10]);
        $customer = $this->customer($r);

        $order = $this->posOrder($r, $item, $staff, $customer->id); // 3 × 10; deduct 3 → 7
        $this->assertSame(7, (int) $item->fresh()->stock_qty, 'create deducted 3');

        PosService::hold($order->fresh());                 // restore → 10
        $this->assertSame(10, (int) $item->fresh()->stock_qty, 'hold returned the stock');

        PosService::resume($order->fresh());               // re-deduct 3 → 7
        $this->assertSame(7, (int) $item->fresh()->stock_qty, 'resume re-deducted 3');

        PosService::settle($order->fresh(), 'cash', 30.0); // held_at already null → must NOT deduct again

        // If settle re-deducted (broken $wasHeld guard) stock would be 4. Deduct-once ⇒ 7.
        $this->assertSame(7, (int) $item->fresh()->stock_qty, 'settle after resume did not double-deduct');
    }

    // ── A2: earn claw-back on refund ────────────────────────────────────────────

    public function test_refunding_a_settled_order_claws_back_the_earned_points(): void
    {
        [$r, $item, $staff] = $this->scenario(); // earn_rate 10
        $customer = $this->customer($r);
        $order = $this->posOrder($r, $item, $staff, $customer->id);
        PosService::settle($order->fresh(), 'cash', 30.0);
        $this->assertSame(3, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance);

        PosService::refund($order->fresh(), $staff, 30.0, 'cash', 'return');

        $this->assertSame(0, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance, 'refund reverses the earn');
        $acctId = LoyaltyAccount::forCustomer($customer->id, $r->id)->id;
        $this->assertDatabaseHas('loyalty_ledger_entries', ['loyalty_account_id' => $acctId, 'type' => 'adjustment', 'delta' => -3]);
    }

    public function test_partial_refunds_claw_back_the_refunded_share_once(): void
    {
        [$r, $item, $staff] = $this->scenario(); // earn_rate 10 → 30.0 earns 3
        $customer = $this->customer($r);
        $order = $this->posOrder($r, $item, $staff, $customer->id);
        PosService::settle($order->fresh(), 'cash', 30.0);
        $acctId = LoyaltyAccount::forCustomer($customer->id, $r->id)->id;
        $bal = fn () => (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance;

        PosService::refund($order->fresh(), $staff, 10.0, 'cash', 'partial'); // 1/3 refunded
        $this->assertSame(2, $bal(), 'a third of the order refunded forfeits a third of its points');
        PosService::refund($order->fresh(), $staff, 10.0, 'cash', 'partial'); // 2/3
        $this->assertSame(1, $bal());
        PosService::refund($order->fresh(), $staff, 10.0, 'cash', 'partial'); // all
        $this->assertSame(0, $bal());

        // The ledger records exactly the earned amount as forfeited, never more.
        $this->assertSame(-3, (int) LoyaltyLedger::where('loyalty_account_id', $acctId)
            ->where('type', 'adjustment')->sum('delta'));
        $this->assertSame(0, (int) LoyaltyLedger::where('loyalty_account_id', $acctId)->sum('delta'), 'ledger sum == balance');
    }

    /** P6-240 / J46: points already spent leave a debt; redemption is blocked until repaid. */
    public function test_refund_after_redeeming_the_earned_points_leaves_a_debt(): void
    {
        [$r, $item, $staff] = $this->scenario();
        $customer = $this->customer($r);
        $order = $this->posOrder($r, $item, $staff, $customer->id);
        PosService::settle($order->fresh(), 'cash', 30.0); // earn 3

        LoyaltyAccount::forCustomer($customer->id, $r->id)->redeem(3, 'spent'); // balance 0
        PosService::refund($order->fresh(), $staff, 30.0, 'cash', 'return');

        $acct = LoyaltyAccount::forCustomer($customer->id, $r->id);
        $this->assertSame(-3, (int) $acct->balance, 'the spent points become a debt');
        $this->assertSame((int) $acct->lifetime_earned - (int) $acct->lifetime_redeemed, (int) $acct->balance);
        $this->assertSame(-3, (int) LoyaltyLedger::where('loyalty_account_id', $acct->id)->sum('delta'), 'ledger sum == balance');
        $this->assertFalse($acct->redeem(1, 'again'), 'no redemption while in debt');
        $this->travel(30)->seconds(); // past the redeem debounce: test the balance gate
        $this->assertSame('insufficient', $acct->redeemForReward(1));

        // A replayed full refund path (void/cancel) takes nothing more.
        LoyaltyAccrual::reverseForOrder($order->fresh());
        $this->assertSame(-3, (int) $acct->fresh()->balance);
    }

    // ── P6-253 (J46): the customer is told when a refund takes points back ───────

    /** Recording SMS fake: nothing ever leaves the process. */
    private function fakeSms(): object
    {
        $fake = new class implements Sms
        {
            /** @var list<string> */
            public array $sent = [];

            public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool
            {
                $this->sent[] = $message;

                return true;
            }

            public function normalizePhone(string $phone): string
            {
                return $phone;
            }
        };
        $this->app->instance(Sms::class, $fake);

        return $fake;
    }

    public function test_full_refund_with_positive_balance_notifies_without_the_debt_sentence(): void
    {
        Mail::fake();
        $sms = $this->fakeSms();
        [$r, $item, $staff] = $this->scenario();
        $customer = $this->customer($r);
        $customer->update(['email' => 'ada@example.test']);
        LoyaltyAccount::forCustomer($customer->id, $r->id)->earn(990001, 50, 'seed');
        $order = $this->posOrder($r, $item, $staff, $customer->id);
        PosService::settle($order->fresh(), 'cash', 30.0); // earns 3 → 53

        PosService::refund($order->fresh(), $staff, 30.0, 'cash', 'return');

        $this->assertSame(50, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance);
        Mail::assertSent(LoyaltyPointsClawedBack::class, function ($m) use ($order) {
            return $m->hasTo('ada@example.test') && $m->orderId === $order->id
                && str_contains($m->text, '3 loyalty points') && ! str_contains($m->text, 'Your balance is');
        });
        $this->assertCount(1, $sms->sent);
        $this->assertStringContainsString('3 loyalty points', $sms->sent[0]);
        $this->assertStringNotContainsString('Your balance is', $sms->sent[0]);
    }

    public function test_partial_refund_notifies_once_per_claw_back_and_a_replay_is_silent(): void
    {
        Mail::fake();
        $sms = $this->fakeSms();
        [$r, $item, $staff] = $this->scenario();
        $customer = $this->customer($r);
        $customer->update(['email' => 'ada@example.test']);
        $order = $this->posOrder($r, $item, $staff, $customer->id);
        PosService::settle($order->fresh(), 'cash', 30.0); // earns 3

        PosService::refund($order->fresh(), $staff, 10.0, 'cash', 'partial'); // takes 1
        Mail::assertSent(LoyaltyPointsClawedBack::class, 1);
        $this->assertStringContainsString('1 loyalty points', $sms->sent[0]);

        // Replay of the same cumulative share (void/cancel path) takes nothing, so says nothing.
        LoyaltyAccrual::reverseForOrder($order->fresh(), 1 / 3);
        Mail::assertSent(LoyaltyPointsClawedBack::class, 1);
        $this->assertCount(1, $sms->sent);

        PosService::refund($order->fresh(), $staff, 10.0, 'cash', 'partial'); // takes 1 more
        Mail::assertSent(LoyaltyPointsClawedBack::class, 2);
    }

    public function test_negative_outcome_adds_the_plain_debt_sentence_to_email_and_sms(): void
    {
        Mail::fake();
        $sms = $this->fakeSms();
        [$r, $item, $staff] = $this->scenario(); // reward_threshold 100
        $customer = $this->customer($r);
        $customer->update(['email' => 'ada@example.test']);
        $order = $this->posOrder($r, $item, $staff, $customer->id);
        PosService::settle($order->fresh(), 'cash', 30.0); // earn 3
        LoyaltyAccount::forCustomer($customer->id, $r->id)->redeem(3, 'spent');

        PosService::refund($order->fresh(), $staff, 30.0, 'cash', 'return'); // balance -3

        $expected = 'Your balance is -3 because points from a refunded order were already used. Earn 103 more points to redeem again.';
        Mail::assertSent(LoyaltyPointsClawedBack::class, fn ($m) => str_contains($m->text, $expected));
        $this->assertStringContainsString($expected, $sms->sent[0]);
    }

    public function test_no_contact_details_or_a_delivery_failure_never_fails_the_refund(): void
    {
        Mail::fake();
        $this->app->instance(Sms::class, new class implements Sms
        {
            public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool
            {
                throw new \RuntimeException('sms down');
            }

            public function normalizePhone(string $phone): string
            {
                return $phone;
            }
        });
        [$r, $item, $staff] = $this->scenario();
        $customer = $this->customer($r); // phone only, no e-mail
        $order = $this->posOrder($r, $item, $staff, $customer->id);
        PosService::settle($order->fresh(), 'cash', 30.0);

        PosService::refund($order->fresh(), $staff, 30.0, 'cash', 'return');

        $this->assertSame(0, (int) LoyaltyAccount::forCustomer($customer->id, $r->id)->balance);
        Mail::assertNothingSent();
    }
}
