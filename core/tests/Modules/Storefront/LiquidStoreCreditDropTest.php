<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\Customer;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\LedgerService;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Coupons & Wallets P7-5a: the CustomerDrop exposes the authed diner's own
 * store-credit balance to Liquid themes (account-bound, D3) so a theme can render
 * a balance line and gate a pay-with-credit affordance.
 */
class LiquidStoreCreditDropTest extends TestCase
{
    use RefreshDatabase;

    private function renderer(): LiquidRenderer
    {
        return new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient);
    }

    private function restaurantAndCustomer(float $credit): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'd', 'main_image' => 'a.png',
            'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        $customer = Customer::create(['name' => 'Ada', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);
        if ($credit > 0) {
            $acct = CustomerWalletService::accountFor($customer);
            $clr = LedgerService::accountFor('platform', 0, 'platform', $acct->currency);
            LedgerService::post([
                ['account' => $clr, 'amount' => -$credit, 'allow_negative' => true, 'ref' => "seed:{$customer->id}:clr"],
                ['account' => $acct, 'amount' => $credit, 'ref' => "seed:{$customer->id}:usr"],
            ]);
        }

        return [$store, $customer];
    }

    private function context(Store $store, Customer $customer): array
    {
        return new ThemeContext()->build([
            'store' => $store,
            'postOptions' => store_options($store->id),
            'settings' => [],
            'customer' => $customer,
        ]);
    }

    public function test_customer_store_credit_renders_for_authed_diner(): void
    {
        [$store, $customer] = $this->restaurantAndCustomer(25.0);

        $out = $this->renderer()->render(
            '{% if customer.store_credit > 0 %}HAS:{{ customer.store_credit }}{% endif %}',
            $this->context($store, $customer)
        );

        $this->assertSame('HAS:25', $out, 'balance exposed + gate works');
    }

    public function test_customer_store_credit_zero_for_no_balance(): void
    {
        [$store, $customer] = $this->restaurantAndCustomer(0.0);

        $out = $this->renderer()->render(
            '{% if customer.store_credit > 0 %}HAS{% else %}NONE{% endif %}',
            $this->context($store, $customer)
        );

        $this->assertSame('NONE', $out);
    }
}
