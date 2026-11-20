<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CustomerWalletService;
use App\Services\FeatureAccess;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Coupons & Wallets P3-3: applying store credit to an order — server-priced,
 * capped, recorded on the order, idempotent on retry.
 */
class CustomerWalletSpendTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: Customer} order total = 30 */
    private function orderWithCredit(float $credit): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'X', 'price' => 10, 'active' => '1']);
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customer->id]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 3, 'variation' => 0]);

        if ($credit > 0) {
            $acct = CustomerWalletService::accountFor($customer);
            $clr = LedgerService::accountFor('platform', 0, 'platform', $acct->currency);
            LedgerService::post([
                ['account' => $clr, 'amount' => -$credit, 'allow_negative' => true, 'ref' => "seed:{$customer->id}:clr"],
                ['account' => $acct, 'amount' => $credit, 'ref' => "seed:{$customer->id}:usr"],
            ]);
        }

        return [$order->fresh(), $customer];
    }

    public function test_partial_credit_records_on_order_and_debits_once(): void
    {
        [$order, $customer] = $this->orderWithCredit(20.00); // total 30, credit 20

        $applied = CustomerWalletService::applyToOrder($customer, $order, 25.00);

        $this->assertEqualsWithDelta(20.0, $applied, 0.001, 'capped at balance');
        $order->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $order->wallet_applied, 0.001);
        $this->assertEqualsWithDelta(10.0, $order->amount_due, 0.001, 'remainder due via gateway/cash');
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer), 0.001);
    }

    public function test_apply_is_idempotent_on_retry(): void
    {
        [$order, $customer] = $this->orderWithCredit(20.00);

        $a = CustomerWalletService::applyToOrder($customer, $order, 25.00);
        $b = CustomerWalletService::applyToOrder($customer->fresh(), $order->fresh(), 25.00); // retry

        $this->assertEqualsWithDelta(20.0, $a, 0.001);
        $this->assertEqualsWithDelta(20.0, $b, 0.001, 'retry returns same, no re-debit');
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $order->fresh()->wallet_applied, 0.001, 'not double-counted');
    }

    public function test_credit_never_exceeds_order_total(): void
    {
        [$order, $customer] = $this->orderWithCredit(100.00); // credit 100, order only 30

        $applied = CustomerWalletService::applyToOrder($customer, $order, 100.00);

        $this->assertEqualsWithDelta(30.0, $applied, 0.001, 'capped at the order total');
        $this->assertEqualsWithDelta(0.0, $order->fresh()->amount_due, 0.001, 'fully paid by credit');
        $this->assertEqualsWithDelta(70.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001);
    }

    public function test_no_credit_no_effect(): void
    {
        [$order, $customer] = $this->orderWithCredit(0.0);

        $applied = CustomerWalletService::applyToOrder($customer, $order, 10.00);

        $this->assertEqualsWithDelta(0.0, $applied, 0.001);
        $this->assertEqualsWithDelta(30.0, $order->fresh()->amount_due, 0.001);
    }

    /**
     * Concurrent-drain resilience: balanceFor() is an unlocked read, so a sibling
     * order can spend the credit in the window before debit()'s locked ledger check.
     * That check then throws "Insufficient balance" — which must NOT propagate out of
     * order creation (a 500) but degrade to applying nothing, exactly like the
     * gift-card tender. Reproduced deterministically by overriding balanceFor to return
     * a STALE-HIGH value (the read window) while the real ledger holds only $5, so the
     * cap oversizes and LedgerService::post rejects the debit under its own DB read.
     */
    public function test_reverse_for_order_recredits_balance_and_clears_tender(): void
    {
        [$order, $customer] = $this->orderWithCredit(20.00); // total 30, credit 20
        CustomerWalletService::applyToOrder($customer, $order, 20.00); // wallet_applied 20, balance 0
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001);

        $back = CustomerWalletService::reverseForOrder($order->fresh());

        $this->assertEqualsWithDelta(20.0, $back, 0.001);
        $this->assertEqualsWithDelta(20.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'credit returned');
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->wallet_applied, 0.001, 'tender cleared');
    }

    public function test_reverse_for_order_is_idempotent_no_double_credit(): void
    {
        [$order, $customer] = $this->orderWithCredit(20.00);
        CustomerWalletService::applyToOrder($customer, $order, 20.00);

        $first = CustomerWalletService::reverseForOrder($order->fresh());
        $second = CustomerWalletService::reverseForOrder($order->fresh());

        $this->assertEqualsWithDelta(20.0, $first, 0.001);
        $this->assertEqualsWithDelta(0.0, $second, 0.001, 'second reverse is a no-op');
        $this->assertEqualsWithDelta(20.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'not credited twice');
    }

    /** AUDIT-money L-2: a keyless refund-to-credit and the tender reversal of one order are two credits, not one ref. */
    public function test_refund_to_credit_then_cancel_returns_both_amounts(): void
    {
        [$order, $customer] = $this->orderWithCredit(20.00); // total 30, credit 20
        CustomerWalletService::applyToOrder($customer, $order, 20.00); // wallet_applied 20, balance 0

        CustomerWalletService::refundToCredit($customer->fresh(), 5.00, (int) $order->id);
        $back = CustomerWalletService::reverseForOrder($order->fresh());

        $this->assertEqualsWithDelta(20.0, $back, 0.001);
        $this->assertEqualsWithDelta(25.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'refund 5 + tender 20');
    }

    public function test_reverse_for_order_is_a_noop_without_a_wallet_tender(): void
    {
        [$order, $customer] = $this->orderWithCredit(20.00); // credit funded but never applied

        $this->assertEqualsWithDelta(0.0, CustomerWalletService::reverseForOrder($order->fresh()), 0.001);
        $this->assertEqualsWithDelta(20.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'balance untouched');
    }

    public function test_reverse_for_order_survives_a_de_entitled_store(): void
    {
        [$order, $customer] = $this->orderWithCredit(20.00);
        CustomerWalletService::applyToOrder($customer, $order, 20.00); // wallet_applied 20, balance 0

        // Downgrade: kill customer_wallet ISSUANCE. credit() would now throw — but the
        // reversal posts ledger legs directly and must still return the spent value.
        config(['settings.admin_allow_customer_wallet' => false]);
        FeatureAccess::flush();

        $back = CustomerWalletService::reverseForOrder($order->fresh());

        $this->assertEqualsWithDelta(20.0, $back, 0.001, 'reversal ignores the issuance gate');
        $this->assertEqualsWithDelta(20.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001);
    }

    public function test_pos_void_recredits_a_wallet_paid_order(): void
    {
        [$order, $customer] = $this->orderWithCredit(20.00);
        CustomerWalletService::applyToOrder($customer, $order, 20.00);
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001);

        PosService::void($order->fresh(), null, 'test void');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEqualsWithDelta(20.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'void restored the credit');
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->wallet_applied, 0.001);
    }

    public function test_a_lost_concurrent_drain_degrades_to_zero_not_a_500(): void
    {
        [$order, $customer] = $this->orderWithCredit(5.00); // real ledger balance: $5

        $double = new class extends CustomerWalletService
        {
            public static function balanceFor(Customer $customer, ?string $currency = null): float
            {
                return 50.0; // stale read — the credit was really drained to $5 in the window
            }
        };

        // Cap sees the stale $50, so it tries to debit $50 against a $5 ledger.
        $applied = $double::applyToOrder($customer, $order->fresh(), 50.00);

        $this->assertSame(0.0, $applied, 'a lost race applies nothing instead of throwing');
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->wallet_applied, 0.001, 'nothing recorded on the order');
        // The real balance is untouched — the customer keeps their credit for a retry.
        $this->assertEqualsWithDelta(5.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001);
    }
}
