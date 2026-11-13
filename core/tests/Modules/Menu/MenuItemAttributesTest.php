<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Tests for menu item attribute fields:
 * #4  Scheduled availability (available_from/to/days)
 * #7  Nutrition info (calories, protein, carbs, fat)
 * #12 Per-branch visibility (visible_branches)
 * #21 Per-item notes (addMenuItem type field)
 * #28 Category reorder
 * #31 Daily specials (is_daily_special)
 * #22 Guest reorder (customer guard)
 */
class MenuItemAttributesTest extends TestCase
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
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeCategory(Store $store): MenuCategory
    {
        return MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
    }

    private function postMenuItem(User $owner, Store $store, array $extra = []): TestResponse
    {
        $cat = $this->makeCategory($store);

        return $this->actingAs($owner)->postJson(route('stores.addMenuItem', $store), array_merge([
            'name' => 'Test Item',
            'price' => 9.99,
            'type' => 'veg',
            'category_id' => $cat->id,
        ], $extra));
    }

    // ── #31 Daily specials ──

    /** is_daily_special flag persists */
    public function test_daily_special_flag_persists(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postMenuItem($owner, $store, ['is_daily_special' => true])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('items', [
            'store_id' => $store->id,
            'is_daily_special' => 1,
        ]);
    }

    /** Non-daily-special item has is_daily_special=0 by default */
    public function test_daily_special_defaults_to_zero(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postMenuItem($owner, $store)->assertOk();

        $this->assertDatabaseHas('items', [
            'store_id' => $store->id,
            'is_daily_special' => 0,
        ]);
    }

    // ── #4 Scheduled availability ──

    /** available_from and available_to persist on menu item */
    public function test_scheduled_availability_persists(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postMenuItem($owner, $store, [
            'available_from' => '11:00',
            'available_to' => '14:00',
            'available_days' => 'mon,tue,wed',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('items', [
            'store_id' => $store->id,
            'available_from' => '11:00',
            'available_to' => '14:00',
            'available_days' => 'mon,tue,wed',
        ]);
    }

    /** available_days posted as an array (dashboard form) is normalized to a CSV of day codes */
    public function test_available_days_array_is_normalized_to_csv(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postMenuItem($owner, $store, [
            'available_days' => ['mon', 'wed', 'fri', 'bogus'],
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('items', [
            'store_id' => $store->id,
            'available_days' => 'mon,wed,fri',
        ]);
    }

    /** Malformed available_from rejected */
    public function test_scheduled_availability_rejects_bad_format(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $response = $this->postMenuItem($owner, $store, ['available_from' => '9am']);
        $response->assertOk()->assertJson(['success' => false]);
    }

    // ── Sold out (86) ──

    /** is_sold_out flag persists when the sold-out toggle is submitted */
    public function test_sold_out_flag_persists(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postMenuItem($owner, $store, ['is_sold_out' => 1])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'is_sold_out' => 1]);
    }

    /** Editing an item with the sold-out box ticked keeps it sold out (dialog now carries the toggle) */
    public function test_update_can_set_and_clear_sold_out(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $cat = $this->makeCategory($store);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'X', 'price' => 5, 'type' => 'veg', 'is_sold_out' => 1]);

        // Re-save with the toggle still ticked -> stays sold out.
        $this->actingAs($owner)->postJson(route('stores.updateMenuItem', $store), [
            'id' => $menu->id, 'name' => 'X', 'price' => 5, 'type' => 'veg', 'category_id' => $cat->id, 'is_sold_out' => 1,
        ])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, (int) $menu->fresh()->is_sold_out);

        // Untick the toggle -> back in stock.
        $this->actingAs($owner)->postJson(route('stores.updateMenuItem', $store), [
            'id' => $menu->id, 'name' => 'X', 'price' => 5, 'type' => 'veg', 'category_id' => $cat->id,
        ])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(0, (int) $menu->fresh()->is_sold_out);
    }

    // ── Per-item cost (menu-engineering margin) ──

    /** cost persists and is editable */
    public function test_cost_persists(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postMenuItem($owner, $store, ['cost' => 3.25])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'cost_minor' => 325]);
    }

    // ── Spice level bounds ──

    /** spice level accepts the documented max (3) */
    public function test_spice_level_accepts_max(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postMenuItem($owner, $store, ['spice_level' => 3])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'spice_level' => 3]);
    }

    /** spice level above the max is rejected (UI max now matches server 0-3) */
    public function test_spice_level_above_max_rejected(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postMenuItem($owner, $store, ['spice_level' => 4])
            ->assertOk()->assertJson(['success' => false]);
    }

    // ── #7 Nutrition info ──

    /** Nutrition fields persist on menu item */
    public function test_nutrition_fields_persist(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postMenuItem($owner, $store, [
            'calories' => 350,
            'protein' => 22.5,
            'carbs' => 45.0,
            'fat' => 12.0,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('items', [
            'store_id' => $store->id,
            'calories' => 350,
        ]);
    }

    // ── #12 Per-branch visibility ──

    // ── #28 Sort / category reorder ──

    /** Reorder categories persists positions */
    public function test_reorder_categories_persists(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $cat1 = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat1']);
        $cat2 = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat2']);

        $this->actingAs($owner)
            ->postJson(route('stores.reorderCategory', $store), [
                'position' => [$cat2->id, $cat1->id],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(0, MenuCategory::find($cat2->id)->position);
        $this->assertEquals(1, MenuCategory::find($cat1->id)->position);
    }

    /** Category reorder tenant isolation */
    public function test_reorder_categories_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat', 'position' => 5]);

        $this->actingAs($other)
            ->postJson(route('stores.reorderCategory', $store), [
                'position' => [$cat->id],
            ])
            ->assertNotFound(); // BL-043: the owner tenant scope hides another account's store (was a silent 200)

        $this->assertEquals(5, MenuCategory::find($cat->id)->position);
    }

    // ── #22 Guest reorder ──

    /** Customer can reorder a previous order (returns cart items) */
    public function test_customer_can_reorder(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        // Create a customer for this restaurant
        $customer = Customer::create([
            'store_id' => $store->id,
            'name' => 'Alice',
            'email' => 'alice@test.com',
            'phone' => '555-0001',
            'password' => bcrypt('secret'),
        ]);

        // Create a menu item
        $cat = $this->makeCategory($store);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        // Create an order belonging to this customer
        $order = Order::create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Alice',
            'type' => 'on-table',
            'status' => 'completed',
            'created_at' => now(),
        ]);
        $order->items()->create([
            'item_id' => $menu->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.reorder', ['store' => $store->id, 'order' => $order->id]));

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertArrayHasKey('items', $response->json());
        $this->assertCount(1, $response->json('items'));
    }

    /** Customer cannot reorder another customer's order */
    public function test_customer_cannot_reorder_other_customers_order(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $cat = $this->makeCategory($store);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        $customer1 = Customer::create(['store_id' => $store->id, 'name' => 'Alice', 'email' => 'alice@t.com', 'phone' => '111', 'password' => bcrypt('secret')]);
        $customer2 = Customer::create(['store_id' => $store->id, 'name' => 'Bob',   'email' => 'bob@t.com',   'phone' => '222', 'password' => bcrypt('secret')]);

        $order = Order::create(['store_id' => $store->id, 'customer_id' => $customer1->id, 'customer_name' => 'Alice', 'type' => 'on-table', 'status' => 'completed', 'created_at' => now()]);
        $order->items()->create(['item_id' => $menu->id, 'quantity' => 1]);

        $this->actingAs($customer2, 'customer')
            ->postJson(route('customer.orders.reorder', ['store' => $store->id, 'order' => $order->id]))
            ->assertForbidden();
    }
}
