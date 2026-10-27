<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Orders\OrderPricing;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Coupons & Wallets P7 follow-up: a customer's store credit is denominated in
 * their OWN restaurant's currency, not the ambient config('settings.currency_code')
 * (which the storefront rewrites per request). This guarantees the balance shown
 * at checkout and the account debited when the order is placed are the SAME
 * account — even when the request's ambient currency differs (the submitOrder
 * timing bug: credit applied before the restaurant currency was set in config).
 */
class CustomerWalletCurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: Customer} order total = 30, restaurant currency EUR */
    private function eurOrder(float $credit): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
        // The restaurant trades in EUR, distinct from the platform default (USD).
        StoreOption::updateStoreOption($r->id, 'currency_code', 'EUR');

        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'X', 'price' => 10, 'active' => '1']);
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customer->id]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 3, 'variation' => 0]);

        if ($credit > 0) {
            // credit() derives the currency from the restaurant → EUR account.
            CustomerWalletService::credit($customer, $credit, 'seed', 'seed:'.$customer->id);
        }

        return [$order->fresh(), $customer];
    }

    public function test_credit_is_denominated_in_restaurant_currency(): void
    {
        [, $customer] = $this->eurOrder(20.0);

        // The EUR account holds it; the platform-default (USD) account is empty.
        $this->assertEqualsWithDelta(20.0, CustomerWalletService::balanceFor($customer, 'EUR'), 0.001);
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer, 'USD'), 0.001);
        // Default balanceFor resolves the restaurant currency, not the config default.
        $this->assertEqualsWithDelta(20.0, CustomerWalletService::balanceFor($customer), 0.001);
    }

    public function test_store_credit_is_not_applied_to_a_cross_currency_order(): void
    {
        [$order, $customer] = $this->eurOrder(20.0); // EUR credit, EUR restaurant, total 30
        $order->currency_code = 'USD'; // order explicitly stamped in a different currency
        $order->save();

        // A EUR-credit customer must not wipe a USD order 1:1 — apply nothing, leave the
        // balance intact (mirrors the gift-card cross-currency guard).
        $applied = CustomerWalletService::applyToOrder($customer, $order->fresh(), 20.0);
        $this->assertEqualsWithDelta(0.0, $applied, 0.001);
        $this->assertEqualsWithDelta(20.0, CustomerWalletService::balanceFor($customer, 'EUR'), 0.001);
    }

    public function test_spend_hits_restaurant_currency_even_when_ambient_config_differs(): void
    {
        [$order, $customer] = $this->eurOrder(20.0);

        // Simulate the submitOrder timing: ambient config still on the global default
        // (USD) at credit-apply time, while the restaurant trades in EUR.
        config(['settings.currency_code' => 'USD']);

        $out = OrderPricing::applyCouponAndCredit($order, [
            'owner_id' => 0,
            'use_wallet' => true,
            'customer_id' => $customer->id,
        ]);

        // Credit was found and applied from the EUR account (not the empty USD one).
        $this->assertEqualsWithDelta(20.0, $out['wallet_applied'], 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $order->fresh()->wallet_applied, 0.001);
        $this->assertEqualsWithDelta(10.0, $order->fresh()->amount_due, 0.001);
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer->fresh(), 'EUR'), 0.001);
    }
}
