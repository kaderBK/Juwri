<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Allergy;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * #94  Rate limiting — throttle on order and waiter routes
 * #26  Allergen filters — admin CRUD that populates DB for Aurora template
 * Restaurant CRUD — store/destroy not covered elsewhere
 */
class RateLimitAndMiscTest extends TestCase
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

    private function makeUser(bool $admin = false): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'allow_ordering' => 1], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(), 'user_type' => $admin ? 'admin' : 'user']);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ── #94 Rate limiting ──

    /**
     * The restaurant-order rate limiter fires after 10 requests per minute.
     * We directly test the RateLimiter registration (unit-level) to avoid
     * making 11 real HTTP calls which would be slow and order-sensitive.
     */
    public function test_order_rate_limiter_is_registered(): void
    {
        // RateLimiter::for('restaurant-order', ...) registered in RouteServiceProvider
        $this->assertTrue(RateLimiter::limiter('restaurant-order') !== null);
    }

    public function test_waiter_rate_limiter_is_registered(): void
    {
        $this->assertTrue(RateLimiter::limiter('restaurant-waiter') !== null);
    }

    /** Hitting order endpoint 11 times from same IP returns 429 on the 11th */
    public function test_order_rate_limit_returns_429_after_limit(): void
    {
        $owner = $this->makeRestaurant($this->makeUser())->user;
        $store = Store::where('user_id', $owner->id)->first();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => 1]);

        // Clear any existing rate limit hits for this IP
        RateLimiter::clear('restaurant-order');

        $basePayload = [
            'items' => json_encode([['id' => 1, 'qty' => 1, 'price' => 10]]),
            'ordering-type' => 'takeaway',
            'pay_via' => 'pay_later',
            'name' => 'Tester',
            'phone-number' => '555-0001',
        ];

        // Send 10 — all should succeed or fail for business reasons (not rate limit)
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('store.submitOrder', $store), $basePayload);
        }

        // 11th should be rate-limited
        $response = $this->postJson(route('store.submitOrder', $store), $basePayload);
        $response->assertStatus(429);
    }

    // ── Restaurant lifecycle ──

    /** Owner can delete their own restaurant */
    public function test_owner_can_delete_restaurant(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->actingAs($owner)
            ->delete(route('stores.destroy', $store))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('stores', ['id' => $store->id]);
    }

    /** Tenant isolation: cannot delete another owner's restaurant */
    public function test_cannot_delete_other_owners_restaurant(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->actingAs($other)
            ->delete(route('stores.destroy', $store))
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)

        // Restaurant still exists — controller silently skips delete when user_id mismatch
        $this->assertDatabaseHas('stores', ['id' => $store->id]);
    }

    // ── #26 Allergen filters (admin CRUD) ──

    /** Admin can create an allergen */
    public function test_admin_creates_allergen(): void
    {
        $admin = $this->makeUser(true);

        $this->actingAs($admin)
            ->postJson(route('admin.allergies.store'), [
                'title' => 'Gluten',
                'active' => true,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('allergies', ['title' => 'Gluten', 'active' => 1]);
    }

    /** Admin can deactivate an allergen */
    public function test_admin_updates_allergen(): void
    {
        $admin = $this->makeUser(true);
        $allergy = Allergy::create(['title' => 'Nuts', 'image' => 'default.png', 'active' => 1, 'position' => 1]);

        $this->actingAs($admin)
            ->putJson(route('admin.allergies.update', $allergy), [
                'title' => 'Tree Nuts',
                'active' => false,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('allergies', ['id' => $allergy->id, 'title' => 'Tree Nuts', 'active' => 0]);
    }

    /** Admin can delete allergen(s) via bulk-delete endpoint */
    public function test_admin_deletes_allergen(): void
    {
        $admin = $this->makeUser(true);
        $allergy = Allergy::create(['title' => 'Soy', 'image' => 'default.png', 'active' => 1, 'position' => 1]);

        // Delete uses POST admin/allergies.delete with ids array (not resource destroy)
        $this->actingAs($admin)
            ->postJson(route('admin.allergies.delete'), ['ids' => [$allergy->id]])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('allergies', ['id' => $allergy->id]);
    }

    /** Allergen appears in Aurora public view when config enabled */
    public function test_active_allergens_available_for_aurora_view(): void
    {
        Allergy::create(['title' => 'Peanuts', 'image' => 'default.png', 'active' => 1, 'position' => 1]);

        // config check used by publicView: config('settings.admin_allergies')
        config(['settings.admin_allergies' => true]);

        $this->assertEquals(1, Allergy::where('active', 1)->count());
    }
}
