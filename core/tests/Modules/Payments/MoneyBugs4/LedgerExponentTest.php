<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MoneyBugs4;

use App\Models\CateringRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\Catering\CateringService;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use App\Services\ReservationDepositService;
use App\Support\Money\TenderAmount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Feature\MoneyBugs4\BeforeAfter;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Bug batch 4 · 1 — the ledger posts each leg at its account currency's ISO exponent (KWD/OMR fils, USD cents,
 * JPY whole yen) instead of a 2-decimal cap (the old A7 limitation). Legs still sum to exactly zero in integer
 * minor units; USD and JPY are unchanged.
 */
class LedgerExponentTest extends TestCase
{
    use BeforeAfter;
    use RefreshDatabase;

    private const D = 0.000001;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.currency_code' => 'USD']);
    }

    private function fund(string $currency, float $amount, int $owner = 1): LedgerAccount
    {
        $platform = LedgerService::accountFor('platform', 0, 'platform', $currency);
        $wallet = LedgerService::accountFor('merchant', $owner, 'merchant', $currency);
        LedgerService::transfer($platform, $wallet, $amount, 'f-'.$currency.'-'.$owner, 'fund', true);

        return $wallet;
    }

    /** Wallet → platform clearing (the clearing leg may run negative, as in every real spend). */
    private function spend(LedgerAccount $wallet, LedgerAccount $platform, float $amount, string $ref): string
    {
        return LedgerService::post([
            ['account' => $wallet, 'amount' => -$amount, 'ref' => $ref.':w'],
            ['account' => $platform, 'amount' => $amount, 'ref' => $ref.':p', 'allow_negative' => true],
        ]);
    }

    /** @return array{0: Store, 1: Order, 2: Customer} */
    private function order(string $currency, float $price, int $qty): array
    {
        $r = $this->store($currency);
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'X', 'price' => $price, 'active' => '1']);
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customer->id]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty, 'variation' => 0]);

        return [$r, $order->fresh(), $customer];
    }

    /** @param  array<string, mixed>  $options */
    private function store(string $currency, array $options = []): Store
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($r->id, 'currency_code', $currency);
        foreach ($options as $k => $v) {
            StoreOption::updateStoreOption($r->id, $k, $v);
        }

        return $r;
    }

    public function test_kwd_and_omr_legs_post_fils(): void
    {
        $kwd = $this->fund('KWD', 1.235, 1);
        $omr = $this->fund('OMR', 0.4565, 2);
        $entry = LedgerEntry::where('ledger_account_id', $kwd->id)->first();

        $this->assertSame($this->ba(1.24, 1.235), LedgerService::balanceFor($kwd));
        $this->assertSame($this->ba('1.24', '1.235'), $entry->amount);
        $this->assertSame($this->ba('1.24', '1.235'), $entry->balance_after);
        $this->assertSame($this->ba('1.24', '1.235'), $entry->toArray()['amount']);
        $this->assertSame($this->ba(0.46, 0.457), LedgerService::balanceFor($omr));
        $this->assertSame($this->ba(2, 3), LedgerService::postingDecimals('KWD'));
    }

    public function test_usd_and_jpy_legs_are_unchanged(): void
    {
        $usd = $this->fund('USD', 2.255, 3);
        $jpy = $this->fund('JPY', 1234.5, 4);

        $this->assertSame(2.26, LedgerService::balanceFor($usd));
        $this->assertSame('2.26', LedgerEntry::where('ledger_account_id', $usd->id)->first()->amount);
        $this->assertSame(1235.0, LedgerService::balanceFor($jpy));
        $this->assertSame('1235.00', LedgerEntry::where('ledger_account_id', $jpy->id)->first()->amount);
        $this->assertSame(2, LedgerService::postingDecimals('USD'));
        $this->assertSame(0, LedgerService::postingDecimals('JPY'));
    }

    public function test_legs_must_sum_to_zero_in_fils(): void
    {
        $platform = LedgerService::accountFor('platform', 0, 'platform', 'KWD');
        $wallet = LedgerService::accountFor('merchant', 5, 'merchant', 'KWD');

        // 1.004 out, 1.000 in: zero at 2 decimals, 4 fils short at 3.
        $posted = true;
        try {
            LedgerService::post([
                ['account' => $platform, 'amount' => -1.004, 'allow_negative' => true],
                ['account' => $wallet, 'amount' => 1.0],
            ]);
        } catch (\InvalidArgumentException) {
            $posted = false;
        }
        $this->assertSame($this->ba(true, false), $posted);
    }

    public function test_kwd_overdraw_by_one_fils_is_refused(): void
    {
        $wallet = $this->fund('KWD', 1.235, 6);
        $platform = LedgerService::accountFor('platform', 0, 'platform', 'KWD');

        $posted = true;
        try {
            $this->spend($wallet, $platform, 1.236, 'over');
        } catch (\RuntimeException) {
            $posted = false;
        }
        $this->assertSame($this->ba(true, false), $posted);
        $this->assertSame($this->ba(0.0, 1.235), LedgerService::balanceFor($wallet));
    }

    public function test_kwd_spend_and_reversal_are_exact(): void
    {
        $wallet = $this->fund('KWD', 1.235, 8);
        $platform = LedgerService::accountFor('platform', 0, 'platform', 'KWD');

        $tx = $this->spend($wallet, $platform, 0.005, 'spend-5');
        $this->assertSame(1.23, LedgerService::balanceFor($wallet));                       // before: 1.24 − 0.01
        LedgerService::reverse($tx, 'oops');
        $this->assertSame($this->ba(1.24, 1.235), LedgerService::balanceFor($wallet));
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_reconcile_compares_in_minor_units(): void
    {
        $wallet = $this->fund('KWD', 1.235, 7);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));

        // A 1-fils drift on the snapshot (inside the old 0.005 tolerance) is reported.
        LedgerEntry::where('ledger_account_id', $wallet->id)->first()->update(['balance_after' => 1.236]);
        $this->assertSame($this->ba(0, 1), Artisan::call('ledger:reconcile'));
    }

    public function test_kwd_store_credit_tender_and_its_reversal_keep_fils(): void
    {
        [, $order, $customer] = $this->order('KWD', 1.125, 3);                            // 3.375
        $this->assertEqualsWithDelta($this->ba(6.13, 6.125), CustomerWalletService::credit($customer, 6.125, 'seed', 'seed:'.$customer->id), self::D);

        $applied = CustomerWalletService::applyToOrder($customer, $order, 10);
        $this->assertEqualsWithDelta($this->ba(3.37, 3.375), $applied, self::D);
        $this->assertEqualsWithDelta($this->ba(0.005, 0.0), $order->fresh()->amount_due, self::D);
        $this->assertEqualsWithDelta($this->ba(2.76, 2.75), CustomerWalletService::balanceFor($customer->fresh()), self::D);

        $this->assertEqualsWithDelta($this->ba(3.37, 3.375), CustomerWalletService::reverseForOrder($order->fresh()), self::D);
        $this->assertEqualsWithDelta($this->ba(6.13, 6.125), CustomerWalletService::balanceFor($customer->fresh()), self::D);
    }

    public function test_kwd_gift_card_issue_redeem_and_refund_keep_fils(): void
    {
        [$r, $order] = $this->order('KWD', 1.125, 3);
        $card = GiftCardService::issueManual($r->id, 5.125)->fresh();
        $this->assertEqualsWithDelta($this->ba(5.13, 5.125), $card->balance(), self::D);
        $this->assertEqualsWithDelta($this->ba(5.13, 5.125), (float) $card->initial_value, self::D);

        $this->assertEqualsWithDelta($this->ba(3.37, 3.375), GiftCardService::applyToOrder($card, $order), self::D);
        $this->assertEqualsWithDelta($this->ba(1.76, 1.75), $card->fresh()->balance(), self::D);

        $this->assertEqualsWithDelta($this->ba(3.37, 3.375), GiftCardService::reverseRedemption($order->fresh()), self::D);
        $this->assertEqualsWithDelta($this->ba(5.13, 5.125), $card->fresh()->balance(), self::D);
    }

    public function test_jpy_and_usd_tenders_unchanged(): void
    {
        [, $order, $customer] = $this->order('JPY', 333, 3);                              // 999
        CustomerWalletService::credit($customer, 500, 'seed', 'seed:'.$customer->id);
        $this->assertEqualsWithDelta(500.0, CustomerWalletService::applyToOrder($customer, $order, 800), self::D);

        [, $usd, $c2] = $this->order('USD', 1.25, 3);                                     // 3.75
        CustomerWalletService::credit($c2, 2.255, 'seed', 'seed:'.$c2->id);               // posts 2.26
        $this->assertEqualsWithDelta(2.26, CustomerWalletService::applyToOrder($c2, $usd, 5), self::D);
        $this->assertEqualsWithDelta(1.49, $usd->fresh()->amount_due, self::D);
    }

    public function test_tender_steps_follow_the_currency_exponent(): void
    {
        $this->assertEqualsWithDelta($this->ba(3.37, 3.375), TenderAmount::apply('KWD', 3375, 5), self::D);
        $this->assertEqualsWithDelta($this->ba(0.78, 0.787), TenderAmount::floorShare(1.125, 0.70, 'KWD'), self::D);
        $this->assertEqualsWithDelta(0.29, TenderAmount::floorShare(29, 0.01, 'USD'), self::D);
        $this->assertEqualsWithDelta(233.0, TenderAmount::floorShare(333, 0.70, 'JPY'), self::D);
    }

    public function test_kwd_deposits_keep_fils(): void
    {
        $r = $this->store('KWD', ['reservation_deposit_amount' => '2.125', 'reservation_deposit_per' => 'reservation']);
        $this->assertEqualsWithDelta($this->ba(2.13, 2.125), ReservationDepositService::amountFor($r->id, 2), self::D);

        $j = $this->store('JPY', ['reservation_deposit_amount' => '1500', 'reservation_deposit_per' => 'guest']);
        $this->assertEqualsWithDelta(3000.0, ReservationDepositService::amountFor($j->id, 2), self::D);

        $c = $this->store('KWD', ['catering_deposit_enabled' => '1', 'catering_deposit_amount' => '10.125']);
        $req = CateringRequest::create([
            'store_id' => $c->id, 'name' => 'E', 'event_at' => now()->addDays(10), 'headcount' => 20, 'status' => 'requested',
        ]);
        $svc = app(CateringService::class);
        $svc->quote($req, 120.345);
        $this->assertSame($this->ba('120.350', '120.345'), $req->fresh()->quote_total);
        $svc->armDeposit($req->fresh());
        $this->assertSame($this->ba('10.130', '10.125'), $req->fresh()->deposit_amount);
    }
}
