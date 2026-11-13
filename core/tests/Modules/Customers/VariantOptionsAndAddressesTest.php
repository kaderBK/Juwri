<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Models\CustomerAddress;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Variant option groups (Size/Color/etc.) — owner CRUD
 * Customer saved addresses — store, delete, default promotion, isolation
 */
class VariantOptionsAndAddressesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id,
            'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::random(5),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234',
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeMenuItem(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Burger', 'price' => 10, 'active' => 1]);
    }

    private function makeCustomer(Store $store): Customer
    {
        return Customer::create([
            'store_id' => $store->id,
            'name' => 'Diner',
            'email' => Str::random(6).'@example.com',
            'phone' => '555'.Str::random(4),
            'session_hash' => Str::random(32),
        ]);
    }

    // ── Variant Option Groups ──

    /** Owner creates a variant option group (e.g. "Size" with options Small, Medium, Large) */
    public function test_owner_can_add_variant_option(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);

        $this->actingAs($owner)
            ->post(route('stores.menuAddVariantOption', [$store, $item]), [
                'title' => 'Size',
                'options' => 'Small, Medium, Large',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('modifier_groups', [
            'menu_id' => $item->id,
            'title' => 'Size',
        ]);
    }

    /** Tenant isolation: other owner cannot add option group to someone else's item */
    public function test_other_owner_cannot_add_variant_option(): void
    {
        $owner = $this->makeOwner();
        $other = $this->makeOwner();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);

        $this->actingAs($other)
            ->post(route('stores.menuAddVariantOption', [$store, $item]), [
                'title' => 'Hack',
                'options' => 'A, B',
            ])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)

        $this->assertDatabaseMissing('modifier_groups', ['menu_id' => $item->id, 'title' => 'Hack']);
    }

    /** Owner updates title + options on existing variant option group */
    public function test_owner_can_update_variant_option(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);
        $opt = MenuVariantOption::create(['menu_id' => $item->id, 'title' => 'Old', 'options' => ['A']]);

        $this->actingAs($owner)
            ->postJson(route('stores.menuUpdateVariantOption', [$store, $item]), [
                'id' => $opt->id,
                'title' => 'Colour',
                'options' => 'Red, Blue, Green',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('modifier_groups', ['id' => $opt->id, 'title' => 'Colour']);
    }

    /** Owner deletes variant option group */
    public function test_owner_can_delete_variant_option(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);
        $opt = MenuVariantOption::create(['menu_id' => $item->id, 'title' => 'Spice', 'options' => ['Mild']]);

        $this->actingAs($owner)
            ->postJson(route('stores.menuDeleteVariantOption', [$store, $item]), [
                'id' => $opt->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('modifier_groups', ['id' => $opt->id]);
    }

    /** Owner reorders variant option groups — position updated */
    public function test_owner_can_reorder_variant_options(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);
        $o1 = MenuVariantOption::create(['menu_id' => $item->id, 'title' => 'A', 'options' => ['x']]);
        $o2 = MenuVariantOption::create(['menu_id' => $item->id, 'title' => 'B', 'options' => ['y']]);

        $this->actingAs($owner)
            ->postJson(route('stores.menuReorderVariantOption', [$store, $item]), [
                'position' => [$o2->id, $o1->id],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('modifier_groups', ['id' => $o2->id, 'position' => 0]);
        $this->assertDatabaseHas('modifier_groups', ['id' => $o1->id, 'position' => 1]);
    }

    // ── Customer Saved Addresses ──

    /** Customer can save a delivery address — persists + auto-default on first */
    public function test_customer_can_save_address(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeRestaurant($owner);
        $customer = $this->makeCustomer($store);

        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store', $store->id), [
                'line' => '42 High Street',
                'city' => 'London',
                'country' => 'GB',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'line' => '42 High Street',
            'is_default' => 1, // first address auto-defaults
        ]);
    }

    /** Second address without is_default flag does NOT steal default */
    public function test_second_address_without_flag_not_default(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeRestaurant($owner);
        $customer = $this->makeCustomer($store);

        // First address (becomes default)
        $first = CustomerAddress::create([
            'customer_id' => $customer->id, 'line' => '1 Main St',
            'is_default' => 1,
        ]);

        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store', $store->id), [
                'line' => '2 Side St',
            ])
            ->assertRedirect();

        // Second address should NOT be default
        $second = CustomerAddress::where('line', '2 Side St')->first();
        $this->assertEquals(0, $second->is_default);
        // First still default
        $this->assertEquals(1, $first->fresh()->is_default);
    }

    /** Customer can delete own address */
    public function test_customer_can_delete_address(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeRestaurant($owner);
        $customer = $this->makeCustomer($store);
        $address = CustomerAddress::create([
            'customer_id' => $customer->id, 'line' => 'Del St', 'is_default' => 1,
        ]);

        $this->actingAs($customer, 'customer')
            ->delete(route('customer.addresses.destroy', [$store->id, $address->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('customer_addresses', ['id' => $address->id]);
    }

    /** Deleting default address promotes next address to default */
    public function test_deleting_default_promotes_next(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeRestaurant($owner);
        $customer = $this->makeCustomer($store);
        $default = CustomerAddress::create([
            'customer_id' => $customer->id, 'line' => 'Default St', 'is_default' => 1,
        ]);
        $next = CustomerAddress::create([
            'customer_id' => $customer->id, 'line' => 'Next St', 'is_default' => 0,
        ]);

        $this->actingAs($customer, 'customer')
            ->delete(route('customer.addresses.destroy', [$store->id, $default->id]))
            ->assertRedirect();

        $this->assertEquals(1, $next->fresh()->is_default);
    }

    /** Tenant isolation: customer cannot delete another customer's address */
    public function test_customer_cannot_delete_other_customers_address(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeRestaurant($owner);
        $c1 = $this->makeCustomer($store);
        $c2 = $this->makeCustomer($store);

        $address = CustomerAddress::create([
            'customer_id' => $c1->id, 'line' => 'Secret St', 'is_default' => 1,
        ]);

        $this->actingAs($c2, 'customer')
            ->delete(route('customer.addresses.destroy', [$store->id, $address->id]))
            ->assertStatus(403);

        $this->assertDatabaseHas('customer_addresses', ['id' => $address->id]);
    }

    /** Address requires line field */
    public function test_address_requires_line(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeRestaurant($owner);
        $customer = $this->makeCustomer($store);

        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store', $store->id), [
                'city' => 'London',
                // no line
            ])
            ->assertRedirect(); // back with errors

        $this->assertDatabaseCount('customer_addresses', 0);
    }
}
