<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

class LoyaltyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        Lang::addLines([
            'lang.loyalty_program_updated' => 'Loyalty program updated.',
            'lang.not_enough_points_to_redeem' => 'Not enough points to redeem.',
            'lang.redemption_failed_please_try_again' => 'Redemption failed. Please try again.',
        ], 'en');

        File::partialMock()
            ->shouldReceive('directories')
            ->with(base_path('lang'))
            ->andReturn([]);

        // Scaffolding for view shared settings/plans
        $settings = new class(['theme_color' => '#111111', 'site_title' => 'juwri', 'meta_description' => 'Test description', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };

        $plan = TestPlans::create([
            'name' => 'Test Plan',
            'description' => 'Test plan',
            'monthly_price' => 0,
            'annual_price' => 0,
            'lifetime_price' => 0,
            'settings' => [
                'allow_viewonly' => '1',
                'allow_pro' => '1',
                'restaurant_limit' => 999,
            ],
            'status' => 1,
        ]);

        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
            'plan' => $plan,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function createRestaurant(User $owner, string $title = 'Pizza Palace'): Store
    {
        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'pizza-palace-'.rand(1000, 9999),
            'color' => '#ff5500',
            'title' => $title,
            'sub_title' => 'Test restaurant',
            'main_image' => 'store.png',
            'cover_image' => 'store.png',
            'address' => '123 Main St',
            'phone' => '123456789',
        ]);
    }

    public function test_vendor_can_configure_loyalty_program(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->createRestaurant($owner);

        session(['template' => 'juwri']);

        // Check settings index route renders
        $response = $this->actingAs($owner)->get(route('loyalty.settings', ['storeId' => $store->id]));
        $response->assertStatus(200);

        // Update settings
        $updateResponse = $this->actingAs($owner)->post(route('loyalty.update', ['storeId' => $store->id]), [
            'type' => 'stamps',
            'earn_rate' => 1,
            'reward_threshold' => 10,
            'reward_description' => 'Get 1 Free Pizza',
            'reward_discount' => 15.00,
            'active' => 1,
        ]);

        $updateResponse->assertRedirect();

        $this->assertDatabaseHas('loyalty_programs', [
            'store_id' => $store->id,
            'type' => 'stamps',
            'earn_rate' => 1,
            'reward_threshold' => 10,
            'reward_discount_minor' => 1500, // P1-05: 15.00 in the store currency
            'active' => 1,
        ]);
    }

    public function test_customer_can_earn_and_redeem_loyalty_rewards(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->createRestaurant($owner);

        // Setup Loyalty Program
        $program = LoyaltyProgram::create([
            'store_id' => $store->id,
            'type' => 'stamps',
            'earn_rate' => 1,
            'reward_threshold' => 5, // 5 stamps needed
            'reward_description' => 'Free Drink',
            'reward_discount' => 5.00,
            'active' => 1,
        ]);

        $customer = Customer::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password'),
            'store_id' => $store->id,
        ]);

        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);
        $this->assertEquals(0, $account->balance);

        // 1. Earn points idempotently
        $earnSuccess = $account->earn(12345, 3, 'Earn for order 12345');
        $this->assertTrue($earnSuccess);
        $this->assertEquals(3, $account->balance);

        // Duplicate earn should be no-op
        $duplicateEarn = $account->earn(12345, 3, 'Earn for order 12345');
        $this->assertFalse($duplicateEarn);
        $this->assertEquals(3, $account->balance);

        // 2. Fetch customer balance route (AJAX)
        $balanceResponse = $this->actingAs($customer, 'customer')->getJson(route('publicView.loyalty.balance', ['storeId' => $store->id]));
        $balanceResponse->assertStatus(200)
            ->assertJson([
                'active' => true,
                'balance' => 3,
                'reward_threshold' => 5,
                'ready_to_redeem' => false,
            ]);

        // 3. Try to redeem reward (not enough stamps)
        $redeemFail = $this->actingAs($customer, 'customer')->postJson(route('publicView.loyalty.redeem', ['storeId' => $store->id]));
        $redeemFail->assertStatus(422)
            ->assertJsonStructure(['error']);

        // Earn more to meet threshold
        $account->earn(54321, 2, 'Earn for order 54321');
        $this->assertEquals(5, $account->balance);

        // 4. Redeem reward (enough stamps now)
        $redeemSuccess = $this->actingAs($customer, 'customer')->postJson(route('publicView.loyalty.redeem', ['storeId' => $store->id]));
        $redeemSuccess->assertStatus(200)
            ->assertJson([
                'success' => true,
                'new_balance' => 0,
                'reward_discount' => 5.00,
            ]);

        $this->assertDatabaseHas('loyalty_ledger_entries', [
            'loyalty_account_id' => $account->id,
            'type' => 'redeem',
            'delta' => -5,
        ]);
    }

    public function test_loyalty_tenant_isolation(): void
    {
        $ownerA = User::factory()->create(['status' => 1]);
        $ownerB = User::factory()->create(['status' => 1]);

        $restaurantA = $this->createRestaurant($ownerA, 'Restaurant A');
        $restaurantB = $this->createRestaurant($ownerB, 'Restaurant B');

        $customer = Customer::create([
            'name' => 'Loyal Cust',
            'email' => 'loyal@example.com',
            'password' => bcrypt('password'),
            'store_id' => $restaurantA->id,
        ]);

        $accountA = LoyaltyAccount::forCustomer($customer->id, $restaurantA->id);
        $accountB = LoyaltyAccount::forCustomer($customer->id, $restaurantB->id);

        $accountA->earn(111, 4, 'Earn Restaurant A');
        $accountB->earn(222, 9, 'Earn Restaurant B');

        $this->assertEquals(4, LoyaltyAccount::forCustomer($customer->id, $restaurantA->id)->balance);
        $this->assertEquals(9, LoyaltyAccount::forCustomer($customer->id, $restaurantB->id)->balance);
    }

    /** Security (AC-1): a customer of store A cannot open a loyalty account at store B. */
    public function test_customer_cannot_use_loyalty_at_another_store(): void
    {
        $restaurantA = $this->createRestaurant(User::factory()->create(['status' => 1]), 'Restaurant A');
        $restaurantB = $this->createRestaurant(User::factory()->create(['status' => 1]), 'Restaurant B');
        LoyaltyProgram::create([
            'store_id' => $restaurantB->id, 'type' => 'stamps', 'earn_rate' => 1,
            'reward_threshold' => 5, 'reward_description' => 'Free Drink', 'reward_discount' => 5.00, 'active' => 1,
        ]);
        $customer = Customer::create([
            'name' => 'Other', 'email' => 'other@example.com', 'password' => bcrypt('password'),
            'store_id' => $restaurantA->id,
        ]);

        $this->actingAs($customer, 'customer')
            ->getJson(route('publicView.loyalty.balance', ['storeId' => $restaurantB->id]))->assertNotFound();
        $this->actingAs($customer, 'customer')
            ->postJson(route('publicView.loyalty.redeem', ['storeId' => $restaurantB->id]))->assertNotFound();

        $this->assertSame(0, LoyaltyAccount::where('store_id', $restaurantB->id)->count());
    }

    public function test_tiers_saved_and_resolved_by_balance(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->createRestaurant($owner);

        $this->actingAs($owner)->post(route('loyalty.update', ['storeId' => $store->id]), [
            'type' => 'points', 'earn_rate' => 1, 'reward_threshold' => 100, 'active' => 1,
            'tiers' => [
                ['name' => 'Gold', 'min' => 100],   // out of order on purpose
                ['name' => 'Silver', 'min' => 50],
                ['name' => '', 'min' => 5],          // unnamed → dropped
            ],
        ])->assertRedirect();

        $p = LoyaltyProgram::where('store_id', $store->id)->first();
        // Stored sorted by min, unnamed dropped.
        $this->assertSame([['name' => 'Silver', 'min' => 50], ['name' => 'Gold', 'min' => 100]], $p->tierList());
        // Resolution: highest tier whose min ≤ balance.
        $this->assertNull($p->tierFor(10));
        $this->assertSame('Silver', $p->tierFor(50));
        $this->assertSame('Silver', $p->tierFor(99));
        $this->assertSame('Gold', $p->tierFor(100));
        $this->assertSame('Gold', $p->tierFor(5000));
    }
}
