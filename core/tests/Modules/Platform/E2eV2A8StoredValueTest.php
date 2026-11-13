<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Platform E2E v2 — A8 stored value: gift card + store credit.
 *
 * Drives the tender services directly (the storefront just posts gift_card_code /
 * use_wallet into the same code paths). Covers issue → redeem → balance,
 * idempotent redemption (re-applying to the SAME order never double-debits), and
 * the insufficient-balance guard (a redemption never over-applies past the
 * remaining stored value — no negative balance).
 */
class E2eV2A8StoredValueTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(100000, 999999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
    }

    /** An order whose live total is exactly `$total` (one menu line). */
    private function order(Store $r, float $total, ?Customer $customer = null): Order
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'active' => '1']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'L', 'price' => $total, 'active' => '1']);
        $order = Order::create(array_filter([
            'store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending',
            'customer_id' => $customer?->id,
        ]));
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0, 'unit_price' => $total]);

        return $order->fresh();
    }

    public function test_gift_card_issue_redeem_balance_idempotent_and_insufficient(): void
    {
        $r = $this->storeFixture();

        // Issue a 50 gift card.
        $card = GiftCardService::issueManual($r->id, 50.00);
        $this->assertEqualsWithDelta(50.00, $card->fresh()->balance(), 0.001, 'issued balance');

        // Redeem 30 against a 30 order → balance 20.
        $order = $this->order($r, 30.00);
        $applied = GiftCardService::applyToOrder($card->fresh(), $order, 30.00);
        $this->assertEqualsWithDelta(30.00, $applied, 0.001, 'redeemed 30');
        $this->assertEqualsWithDelta(20.00, $card->fresh()->balance(), 0.001, 'balance after redeem');

        // Idempotent: re-applying to the SAME order does not double-debit.
        $again = GiftCardService::applyToOrder($card->fresh(), $order->fresh(), 30.00);
        $this->assertEqualsWithDelta(0.00, $again, 0.001, 're-apply to the same order applies nothing');
        $this->assertEqualsWithDelta(20.00, $card->fresh()->balance(), 0.001, 'balance unchanged on re-apply');

        // Insufficient: request 999 on a big order → capped at the remaining 20, never negative.
        $order2 = $this->order($r, 999.00);
        $capped = GiftCardService::applyToOrder($card->fresh(), $order2, 999.00);
        $this->assertEqualsWithDelta(20.00, $capped, 0.001, 'redemption caps at the remaining balance');
        $this->assertEqualsWithDelta(0.00, $card->fresh()->balance(), 0.001, 'balance floors at 0, never negative');
    }

    public function test_store_credit_issue_redeem_balance_idempotent_and_insufficient(): void
    {
        config(['settings.admin_allow_customer_wallet' => true]); // issuance entitlement (test-local)
        $r = $this->storeFixture();
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);

        // Issue 40 store credit.
        CustomerWalletService::credit($customer, 40.00, 'e2e-a8 issue', 'e2e:issue');
        $this->assertEqualsWithDelta(40.00, CustomerWalletService::balanceFor($customer), 0.001, 'issued store credit');

        // Redeem 25 against a 25 order → balance 15.
        $order = $this->order($r, 25.00, $customer);
        $applied = CustomerWalletService::applyToOrder($customer, $order, 25.00);
        $this->assertEqualsWithDelta(25.00, $applied, 0.001, 'redeemed 25');
        $this->assertEqualsWithDelta(15.00, CustomerWalletService::balanceFor($customer), 0.001, 'balance after redeem');

        // Idempotent: re-applying to the same order returns the already-applied
        // amount (not a fresh debit) and leaves the balance untouched.
        $again = CustomerWalletService::applyToOrder($customer, $order->fresh(), 25.00);
        $this->assertEqualsWithDelta(25.00, $again, 0.001, 're-apply reports the existing tender, not a new debit');
        $this->assertEqualsWithDelta(15.00, CustomerWalletService::balanceFor($customer), 0.001, 'balance unchanged on re-apply (no double-debit)');

        // Insufficient: request 999 → capped at the remaining 15, never negative.
        $order2 = $this->order($r, 999.00, $customer);
        $capped = CustomerWalletService::applyToOrder($customer, $order2, 999.00);
        $this->assertEqualsWithDelta(15.00, $capped, 0.001, 'redemption caps at the remaining balance');
        $this->assertEqualsWithDelta(0.00, CustomerWalletService::balanceFor($customer), 0.001, 'balance floors at 0, never negative');
    }
}
