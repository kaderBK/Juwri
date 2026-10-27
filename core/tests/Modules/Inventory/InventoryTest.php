<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'Test', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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

        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
        ]);

        $this->withoutMiddleware();
    }

    private function makePlan(): object
    {
        return TestPlans::create([
            'name' => 'Test Plan '.Str::random(6),
            'description' => 'Test',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
    }

    private function makeUser(object $plan): User
    {
        return User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'Restaurant '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111111',
            'title' => $title,
            'sub_title' => 'Test',
            'description' => 'Test',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 Test St',
            'phone' => '1234567890',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeMenuItem(Store $store, array $attrs = []): Menu
    {
        $cat = MenuCategory::create([
            'store_id' => $store->id,
            'name' => 'Cat '.Str::random(4),
        ]);

        return Menu::create(array_merge([
            'store_id' => $store->id,
            'category_id' => $cat->id,
            'name' => 'Item '.Str::random(4),
            'price' => 10.00,
            'active' => '1',
            'track_inventory' => true,
            'stock_qty' => 20,
            'low_stock_threshold' => 5,
            'is_sold_out' => false,
        ], $attrs));
    }

    /** Index lists tracked items */
    public function test_index_shows_tracked_items(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);

        $this->actingAs($owner)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee($item->name);
    }

    /** Update stock qty persists */
    public function test_update_stock_qty_persists(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store, ['stock_qty' => 10]);

        $this->actingAs($owner)
            ->putJson(route('inventory.update', $item->id), ['stock_qty' => 25])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('items', ['id' => $item->id, 'stock_qty' => 25]);
    }

    /** F4: Menu::restoreStock (order void/restore path) preserves a MANUAL 86. */
    public function test_restore_stock_preserves_a_manual_sold_out(): void
    {
        $r = $this->makeRestaurant($this->makeUser($this->makePlan()));
        // Owner 86'd a tracked item that still has stock — a manual choice, not stock-driven.
        $item = $this->makeMenuItem($r, ['stock_qty' => 10, 'is_sold_out' => true]);

        Menu::restoreStock($item->id, 1); // a later void restores a unit

        $item->refresh();
        $this->assertSame(11, (int) $item->stock_qty);
        $this->assertTrue((bool) $item->is_sold_out, 'a manual 86 is not silently un-86d by a restore');
    }

    /** Regression: a STOCK-driven sold-out still clears when a restore lifts stock above 0. */
    public function test_restore_stock_clears_a_stock_driven_sold_out(): void
    {
        $r = $this->makeRestaurant($this->makeUser($this->makePlan()));
        $item = $this->makeMenuItem($r, ['stock_qty' => 0, 'is_sold_out' => true]); // depleted → stock-driven

        Menu::restoreStock($item->id, 3);

        $item->refresh();
        $this->assertSame(3, (int) $item->stock_qty);
        $this->assertFalse((bool) $item->is_sold_out, 'stock-driven sold-out clears on restore');
    }

    /** Restocking a sold-out item clears is_sold_out */
    public function test_restock_clears_sold_out(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store, ['stock_qty' => 0, 'is_sold_out' => true]);

        $this->actingAs($owner)
            ->putJson(route('inventory.update', $item->id), ['stock_qty' => 10])
            ->assertOk()
            ->assertJson(['ok' => true, 'is_sold_out' => false]);

        $this->assertDatabaseHas('items', ['id' => $item->id, 'is_sold_out' => false, 'stock_qty' => 10]);
    }

    /** Tenant isolation: other owner cannot update */
    public function test_other_owner_cannot_update(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);

        $other = $this->makeUser($this->makePlan());

        $this->actingAs($other)
            ->putJson(route('inventory.update', $item->id), ['stock_qty' => 99])
            ->assertNotFound(); // findOrFail scoped to owner's restaurants
    }

    /** enableAll sets track_inventory on all items */
    public function test_enable_all_enables_tracking(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);

        $cat = MenuCategory::create([
            'store_id' => $store->id,
            'name' => 'Cat',
        ]);

        $item = Menu::create([
            'store_id' => $store->id,
            'category_id' => $cat->id,
            'name' => 'Untracked',
            'price' => 5.00,
            'active' => '1',
            'track_inventory' => false,
        ]);

        $this->actingAs($owner)
            ->post(route('inventory.enableAll'), ['store_id' => $store->id])
            ->assertRedirect();

        $this->assertDatabaseHas('items', ['id' => $item->id, 'track_inventory' => true]);
    }

    /** enableAll blocked for other owner's restaurant */
    public function test_enable_all_tenant_isolation(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);

        $other = $this->makeUser($this->makePlan());

        $this->actingAs($other)
            ->post(route('inventory.enableAll'), ['store_id' => $store->id])
            ->assertForbidden();
    }

    /** H5: the Oversold stat renders with a Clear control once an oversell is recorded. */
    public function test_index_surfaces_the_oversell_stat(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);
        $this->makeMenuItem($store);
        StoreOption::updateStoreOption($store->id, 'stock_oversell_count', 2);
        StoreOption::updateStoreOption($store->id, 'stock_oversell_last_at', now()->toDateTimeString());

        $this->actingAs($owner)
            ->get(route('inventory.index', ['store_id' => $store->id]))->assertOk()->assertSeeHtml(___('Oversold'))->assertSeeHtml(___('Clear'));
    }

    /** H5: the merchant can clear the oversell counter; the action is tenant-scoped. */
    public function test_oversell_reset_clears_the_counter_and_is_tenant_scoped(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);
        StoreOption::updateStoreOption($store->id, 'stock_oversell_count', 3);

        $other = $this->makeUser($this->makePlan());
        $this->actingAs($other)
            ->post(route('inventory.oversellReset'), ['store_id' => $store->id])
            ->assertForbidden();
        $this->assertSame(3, (int) (store_options($store->id, 'stock_oversell_count', 0) ?: 0));

        $this->actingAs($owner)
            ->post(route('inventory.oversellReset'), ['store_id' => $store->id])
            ->assertRedirect();
        $this->assertSame(0, (int) (store_options($store->id, 'stock_oversell_count', 0) ?: 0));
    }
}
