<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Exceptions\StoreCreditCurrencyMismatchException;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Bug batch 8 item 1: refund-to-store-credit uses the ORDER's own currency (decimals and
 * account), never the store's current currency, and credits only a wallet in that currency.
 */
class CustomerWalletRefundCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{0: User, 1: Store, 2: Customer} */
    private function store(string $currency): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 99999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
        StoreSettings::set($r->id, 'currency_code', $currency);
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);

        return [$owner, $r, $customer];
    }

    private function order(Store $r, Customer $customer, string $currency): Order
    {
        return Order::create([
            'store_id' => $r->id, 'customer_id' => $customer->id, 'type' => 'takeaway',
            'status' => 'completed', 'currency_code' => $currency,
        ]);
    }

    public function test_same_currency_refund_credits_the_order_currency_wallet(): void
    {
        [$owner, $r, $customer] = $this->store('USD');
        $order = $this->order($r, $customer, 'USD');

        $this->actingAs($owner)
            ->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 18.5])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsWithDelta(18.5, CustomerWalletService::balanceFor($customer, 'USD'), 0.0001);
    }

    public function test_order_paid_in_another_currency_than_the_wallet_is_refused_and_credits_nothing(): void
    {
        // Order paid in KWD; the store later switched to USD (the customer's wallet is USD).
        [$owner, $r, $customer] = $this->store('KWD');
        $order = $this->order($r, $customer, 'KWD');
        StoreSettings::set($r->id, 'currency_code', 'USD');

        $this->actingAs($owner)
            ->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 10.125])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonFragment(['message' => (string) ___('This order was paid in :currency and the customer store credit is in another currency. Refund through your payment provider instead.', ['currency' => 'KWD'])])
            ->assertSee('KWD');

        $this->assertSame(0, LedgerAccount::where('owner_type', 'customer')->where('owner_id', $customer->id)
            ->whereHas('entries')->count(), 'no store credit was issued in any currency');
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer, 'USD'), 0.0001);
        $this->assertNull($order->fresh()->refund_method);
    }

    public function test_order_currency_decimals_are_used_not_the_store_current_currency(): void
    {
        // Order in JPY (0 decimals), store switched to USD afterwards: before the fix the amount
        // was rounded at USD's 2 decimals and credited to a USD wallet. Now it is refused
        // (another currency) — the number is never credited in USD.
        [$owner, $r, $customer] = $this->store('JPY');
        $order = $this->order($r, $customer, 'JPY');
        StoreSettings::set($r->id, 'currency_code', 'USD');

        $this->actingAs($owner)
            ->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 500])
            ->assertStatus(422);
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer, 'USD'), 0.0001);
    }

    public function test_zero_decimal_currency_refund_rounds_at_the_order_currency(): void
    {
        [$owner, $r, $customer] = $this->store('JPY');
        $order = $this->order($r, $customer, 'JPY');

        $this->actingAs($owner)
            ->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 500.4])
            ->assertOk();

        $this->assertEqualsWithDelta(500.0, CustomerWalletService::balanceFor($customer, 'JPY'), 0.0001);
    }

    public function test_service_refuses_a_refund_in_another_currency(): void
    {
        [, $r, $customer] = $this->store('USD');

        $this->expectException(StoreCreditCurrencyMismatchException::class);
        CustomerWalletService::refundToCredit($customer, 10, 4242, null, 'EUR');
    }
}
