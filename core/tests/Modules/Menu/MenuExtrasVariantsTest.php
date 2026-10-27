<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Menu extras (add-ons) and variants — owner CRUD + tenant isolation.
 * Extras: optional add-ons with title + price (e.g. "Extra cheese +£1")
 * Variants: price-point rows linked to variant-option groups (e.g. Small/Med/Large)
 */
class MenuExtrasVariantsTest extends TestCase
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

    private function makeUser(): User
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

    // ── Extras ──

    /** Owner adds extra → row persists in menu_extras */
    public function test_owner_can_add_extra(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);

        $this->actingAs($owner)
            ->post(route('stores.menuAddExtra', [$store, $item]), [
                'title' => 'Extra Cheese',
                'price' => 1.50,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('modifiers', [
            'menu_id' => $item->id,
            'title' => 'Extra Cheese',
        ]);
    }

    /** Tenant isolation: other owner cannot add extra to someone else's item */
    public function test_other_owner_cannot_add_extra(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);

        $this->actingAs($other)
            ->post(route('stores.menuAddExtra', [$store, $item]), [
                'title' => 'Hack',
                'price' => 0,
            ])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)

        $this->assertDatabaseMissing('modifiers', ['menu_id' => $item->id, 'title' => 'Hack']);
    }

    /** Owner updates extra title + price */
    public function test_owner_can_update_extra(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);
        $extra = MenuExtra::create(['menu_id' => $item->id, 'title' => 'Old', 'price' => 1.00]);

        $this->actingAs($owner)
            ->postJson(route('stores.menuUpdateExtra', [$store, $item]), [
                'id' => $extra->id,
                'title' => 'Jalapeños',
                'price' => 0.75,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('modifiers', ['id' => $extra->id, 'title' => 'Jalapeños']);
    }

    /** Owner deletes extra */
    public function test_owner_can_delete_extra(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);
        $extra = MenuExtra::create(['menu_id' => $item->id, 'title' => 'Sauce', 'price' => 0.50]);

        $this->actingAs($owner)
            ->postJson(route('stores.menuDeleteExtra', [$store, $item]), [
                'id' => $extra->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('modifiers', ['id' => $extra->id]);
    }

    /** Owner reorders extras — position column updated */
    public function test_owner_can_reorder_extras(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);
        $e1 = MenuExtra::create(['menu_id' => $item->id, 'title' => 'A', 'price' => 1]);
        $e2 = MenuExtra::create(['menu_id' => $item->id, 'title' => 'B', 'price' => 1]);

        $this->actingAs($owner)
            ->postJson(route('stores.menuReorderExtra', [$store, $item]), [
                'position' => [$e2->id, $e1->id], // B first, A second
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('modifiers', ['id' => $e2->id, 'position' => 0]);
        $this->assertDatabaseHas('modifiers', ['id' => $e1->id, 'position' => 1]);
    }

    // ── Variants ──

    /** Owner adds variant row */
    public function test_owner_can_add_variant(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);

        $this->actingAs($owner)
            ->post(route('stores.menuAddVariant', [$store, $item]), [
                'price' => 12.99,
                'options' => [],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('menu_variants', [ // P1-05: price_minor
            'menu_id' => $item->id,
            'price_minor' => 1299,
        ]);
    }

    /** Tenant isolation: other owner cannot add variant */
    public function test_other_owner_cannot_add_variant(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);

        $this->actingAs($other)
            ->post(route('stores.menuAddVariant', [$store, $item]), [
                'price' => 99.99,
            ])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)

        $this->assertDatabaseMissing('menu_variants', ['menu_id' => $item->id, 'price_minor' => 9999]);
    }

    /** Owner updates variant price */
    public function test_owner_can_update_variant(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);
        $variant = MenuVariant::create(['menu_id' => $item->id, 'price' => 10.00, 'options' => '[]']);

        $this->actingAs($owner)
            ->postJson(route('stores.menuUpdateVariant', [$store, $item]), [
                'id' => $variant->id,
                'price' => 14.50,
                'options' => [],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('menu_variants', ['id' => $variant->id, 'price_minor' => 1450]);
    }

    /** Owner deletes variant */
    public function test_owner_can_delete_variant(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);
        $variant = MenuVariant::create(['menu_id' => $item->id, 'price' => 10.00, 'options' => '[]']);

        $this->actingAs($owner)
            ->postJson(route('stores.menuDeleteVariant', [$store, $item]), [
                'id' => $variant->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('menu_variants', ['id' => $variant->id]);
    }

    /** Validation: extra without title returns redirect (not 500) */
    public function test_add_extra_requires_title(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);

        $this->actingAs($owner)
            ->post(route('stores.menuAddExtra', [$store, $item]), [
                'price' => 1.00,
                // no title
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('modifiers', 0);
    }

    /** Validation: variant without price returns redirect (not 500) */
    public function test_add_variant_requires_price(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);

        $this->actingAs($owner)
            ->post(route('stores.menuAddVariant', [$store, $item]), [
                // no price
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('menu_variants', 0);
    }
}
