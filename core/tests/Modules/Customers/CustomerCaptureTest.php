<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class CustomerCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeMenuItem(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10.00, 'active' => 1]);
    }

    private function submitOrder(Store $store, Menu $item, array $extra = []): TestResponse
    {
        // P6-351: the on-table branch now resolves a StoreTable row and refuses
        // an unknown one — bake a matching row in.
        StoreTable::firstOrCreate(
            ['store_id' => $store->id, 'table_no' => 5],
            ['key' => Str::random(10)]
        );

        return $this->post(route('store.submitOrder', $store), array_merge([
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'extras' => []]]),
            'name' => 'Test Guest',
            'ordering-type' => 'on-table',
            'table' => '5',
            'pay_via' => 'cash',
            'total' => '10.00',
        ], $extra));
    }

    /** Guest order with phone + email captures a customer record */
    public function test_order_captures_customer_with_phone_and_email(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeMenuItem($store);

        $this->submitOrder($store, $item, [
            'name' => 'Jane Doe',
            'phone-number' => '555-9000',
            'capture_email' => 'jane@test.com',
            'marketing_consent' => '1',
        ]);

        $this->assertDatabaseHas('customers', [
            'store_id' => $store->id,
            'phone' => '555-9000',
            'email' => 'jane@test.com',
            'marketing_consent' => 1,
        ]);
    }

    /** Second order with same phone updates existing customer (upsert) */
    public function test_order_capture_upserts_existing_customer(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeMenuItem($store);

        Customer::create(['store_id' => $store->id, 'name' => 'Old Name', 'phone' => '555-8888', 'marketing_consent' => false]);

        $this->submitOrder($store, $item, [
            'name' => 'Updated Name',
            'phone-number' => '555-8888',
        ]);

        $this->assertEquals(1, Customer::where('store_id', $store->id)->where('phone', '555-8888')->count());
        $this->assertDatabaseHas('customers', ['store_id' => $store->id, 'phone' => '555-8888', 'name' => 'Updated Name']);
    }

    /**
     * GOL: the captured guest order must be LINKED to the customer row (legacy
     * pipeline). The old isset() guard was dead (false for a null attribute), so
     * order.customer_id stayed NULL and the register-adopt proof never matched.
     */
    public function test_guest_order_is_linked_to_captured_customer(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeMenuItem($store);

        $this->submitOrder($store, $item, [
            'name' => 'Linked Guest',
            'phone-number' => '555-4200',
            'capture_email' => 'linked@test.com',
        ]);

        $customer = Customer::where('store_id', $store->id)->where('email', 'linked@test.com')->firstOrFail();
        $order = Order::where('store_id', $store->id)->latest('id')->firstOrFail();

        $this->assertSame($customer->id, $order->customer_id, 'guest order must carry the captured customer id');
    }

    /** Order with no phone/email doesn't create a customer record */
    public function test_order_without_capture_data_skips_customer_creation(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeMenuItem($store);

        $this->submitOrder($store, $item, ['name' => 'Anonymous']);

        $this->assertDatabaseMissing('customers', ['store_id' => $store->id]);
    }
}
