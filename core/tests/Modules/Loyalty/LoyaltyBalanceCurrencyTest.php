<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * The loyalty balance endpoint feeds the checkout redeem strip, and the reward it
 * offers ("Apply X off your order") is the one money figure that route formats.
 *
 * It is NOT a storefront render, so nothing had pointed the currency formatters at
 * the store: a dirham store's checkout offered "$10.00 off" beside ⃃ totals. The
 * strip only became visible once it was placed into the checkout, which is how the
 * mismatch surfaced — worth a test so it cannot drift back unseen.
 */
class LoyaltyBalanceCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** @return array{0: Store, 1: Customer} */
    private function storeWithCurrency(string $code, string $sign): array
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
        StoreOption::updateStoreOption($store->id, 'currency_code', $code);
        StoreOption::updateStoreOption($store->id, 'currency_sign', $sign);

        LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => 100, 'reward_description' => 'Free side', 'reward_discount' => 10.0,
            'active' => true,
        ]);

        $customer = Customer::create([
            'store_id' => $store->id, 'name' => 'Ada', 'phone' => UniquePhone::next('55-'),
        ]);
        LoyaltyAccount::forCustomer($customer->id, $store->id)->earn(999000 + rand(1, 9999), 150, 'seed');

        return [$store, $customer];
    }

    public function test_reward_display_uses_the_stores_currency_not_the_platforms(): void
    {
        // Platform currency deliberately differs from the store's.
        config(['settings.currency_code' => 'USD', 'settings.currency_sign' => '$']);

        [$store, $customer] = $this->storeWithCurrency('AED', 'د.إ');

        $display = $this->actingAs($customer, 'customer')
            ->getJson(route('publicView.loyalty.balance', ['storeId' => $store->id]))
            ->assertOk()
            ->json('reward_discount_display');

        $this->assertStringContainsString('د.إ', (string) $display);
        $this->assertStringNotContainsString('$', (string) $display);
    }

    public function test_a_store_on_the_platform_currency_is_unaffected(): void
    {
        config(['settings.currency_code' => 'USD', 'settings.currency_sign' => '$']);

        [$store, $customer] = $this->storeWithCurrency('USD', '$');

        $this->actingAs($customer, 'customer')
            ->getJson(route('publicView.loyalty.balance', ['storeId' => $store->id]))
            ->assertOk()
            ->assertJsonPath('balance', 150)
            ->assertJsonPath('ready_to_redeem', true);
    }

    public function test_a_store_that_never_picked_a_currency_falls_back_instead_of_blanking(): void
    {
        config(['settings.currency_code' => 'USD', 'settings.currency_sign' => '$']);

        // No currency_code / currency_sign options at all — the USD fallback in
        // storefront_apply_store_currency() is what keeps the offer printable.
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
        LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => 100, 'reward_description' => 'Free side', 'reward_discount' => 10.0,
            'active' => true,
        ]);
        $customer = Customer::create([
            'store_id' => $store->id, 'name' => 'Ada', 'phone' => UniquePhone::next('55-'),
        ]);
        LoyaltyAccount::forCustomer($customer->id, $store->id)->earn(999000 + rand(1, 9999), 150, 'seed');

        $display = $this->actingAs($customer, 'customer')
            ->getJson(route('publicView.loyalty.balance', ['storeId' => $store->id]))
            ->assertOk()
            ->json('reward_discount_display');

        $this->assertStringContainsString('10.00', (string) $display);
    }
}
