<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

class CustomerCRMTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $settings = new class(['theme_color' => '#111111', 'site_title' => 'juwri', 'meta_description' => 'Test description', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'store_order_whatsapp_message' => 'Test whatsapp msg'])
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
            'name' => 'Free Plan',
            'description' => 'Free membership plan',
            'monthly_price' => 0,
            'annual_price' => 0,
            'lifetime_price' => 0,
            'settings' => [
                'allow_viewonly' => '1',
                'allow_pro' => '1',
            ],
            'status' => 1,
        ]);
        config(['settings.free_membership_plan' => $plan]);

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

    private function createTestRestaurant(User $user, string $title = 'Pizza Paradise', string $slug = 'pizza-paradise'): Store
    {
        return Store::create([
            'user_id' => $user->id,
            'title' => $title,
            'slug' => $slug,
            'color' => '#ff5500',
            'sub_title' => 'Test restaurant',
            'description' => 'Test description',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 Test Street',
            'phone' => '1234567890',
        ]);
    }

    public function test_merchant_can_list_their_customers()
    {
        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        $customer1 = Customer::create([
            'store_id' => $store->id,
            'name' => 'Alice Diner',
            'email' => 'alice@diner.com',
            'phone' => '111111111',
            'orders_count' => 3,
            'total_spent' => 150.00,
            'last_order_at' => now()->subDays(2),
        ]);

        $customer2 = Customer::create([
            'store_id' => $store->id,
            'name' => 'Bob Diner',
            'email' => 'bob@diner.com',
            'phone' => '222222222',
            'orders_count' => 1,
            'total_spent' => 25.00,
            'last_order_at' => now()->subDays(10),
        ]);

        $this->actingAs($merchant);

        $response = $this->get(route('customers.index'));
        $response->assertStatus(200);
        $response->assertSee('Alice Diner');
        $response->assertSee('Bob Diner');
    }

    /** The "new this month" segment uses the selected store's month, not the platform's. */
    public function test_new_this_month_segment_uses_store_timezone(): void
    {
        // Platform (Asia/Qatar) has ticked into August; New York is still on July 31.
        Carbon::setTestNow(Carbon::parse('2026-08-01 02:00:00', 'UTC'));

        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);
        StoreOption::updateStoreOption($store->id, 'timezone', 'America/New_York');

        // Signed up 2026-07-31 23:00 platform time = 16:00 July 31 in New York — still
        // "this month" on the store's clock, but "last month" on the platform's.
        $c = Customer::create([
            'store_id' => $store->id, 'name' => 'July Joiner',
            'email' => 'july@diner.com', 'phone' => '333333333',
        ]);
        $c->created_at = '2026-07-31 23:00:00';
        $c->save();

        $this->actingAs($merchant);
        $response = $this->get(route('customers.index', ['segment' => 'new_this_month']));
        $response->assertStatus(200);
        $response->assertSee('July Joiner'); // present only when judged on the store's month

        Carbon::setTestNow();
    }

    public function test_merchant_cannot_see_other_merchants_customers()
    {
        $merchant1 = User::factory()->create(['status' => 1]);
        $restaurant1 = $this->createTestRestaurant($merchant1, 'Restaurant 1', 'res-1');

        $merchant2 = User::factory()->create(['status' => 1]);
        $restaurant2 = $this->createTestRestaurant($merchant2, 'Restaurant 2', 'res-2');

        $customerOfMerchant2 = Customer::create([
            'store_id' => $restaurant2->id,
            'name' => 'Secret Customer',
            'email' => 'secret@diner.com',
        ]);

        // Access as Merchant 1
        $this->actingAs($merchant1);

        $response = $this->get(route('customers.index'));
        $response->assertStatus(200);
        $response->assertDontSee('Secret Customer');

        // Access specific profile direct view
        $showResponse = $this->get(route('customers.show', $customerOfMerchant2->id));
        $showResponse->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
    }

    public function test_merchant_can_update_tags_and_notes()
    {
        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        $customer = Customer::create([
            'store_id' => $store->id,
            'name' => 'Charlie Diner',
            'email' => 'charlie@diner.com',
        ]);

        $this->actingAs($merchant);

        $response = $this->put(route('customers.update', $customer->id), [
            'tags' => 'VIP, Frequent',
            'notes' => 'Likes extra spicy pizza sauce.',
        ]);

        $response->assertStatus(302); // Redirect back
        $customer->refresh();
        $this->assertEquals('VIP, Frequent', $customer->tags);
        $this->assertEquals('Likes extra spicy pizza sauce.', $customer->notes);
    }

    public function test_merchant_can_export_csv()
    {
        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        Customer::create([
            'store_id' => $store->id,
            'name' => 'Alice Diner',
            'email' => 'alice@diner.com',
            'phone' => '111111111',
            'orders_count' => 3,
            'total_spent' => 150.00,
        ]);

        $this->actingAs($merchant);

        $response = $this->get(route('customers.export'));
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $csvContent = $response->streamedContent();
        $this->assertStringContainsString('Alice Diner', $csvContent);
        $this->assertStringContainsString('alice@diner.com', $csvContent);
    }
}
