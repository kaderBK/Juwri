<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Coupons & Wallets P3-4: the diner account payload surfaces store-credit
 * balance + history.
 */
class CustomerWalletAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_account_json_includes_store_credit_balance(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);

        $acct = CustomerWalletService::accountFor($customer);
        $clr = LedgerService::accountFor('platform', 0, 'platform', $acct->currency);
        LedgerService::post([
            ['account' => $clr, 'amount' => -45, 'allow_negative' => true, 'ref' => 'seed:clr'],
            ['account' => $acct, 'amount' => 45, 'ref' => 'seed:usr'],
        ]);

        $this->actingAs($customer, 'customer')
            ->getJson(route('customer.account', $r->id))
            ->assertOk()
            ->assertJsonPath('store_credit.balance', 45);
    }
}
