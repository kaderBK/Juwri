<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\MenuReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class DietaryRatingSchemaTest extends TestCase
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

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeMenu(Store $store, array $attrs = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create(array_merge([
            'store_id' => $store->id,
            'category_id' => $cat->id,
            'name' => 'Item',
            'price' => 10.00,
            'active' => 1,
        ], $attrs));
    }

    // ── #96 Dietary schema ──

    /** Dietary flags persist on menu item */
    public function test_dietary_flags_persist(): void
    {
        $store = $this->makeRestaurant();
        $menu = $this->makeMenu($store, ['is_vegan' => true, 'is_gluten_free' => true, 'is_halal' => false, 'spice_level' => 2]);

        $this->assertDatabaseHas('items', [
            'id' => $menu->id,
            'is_vegan' => 1,
            'is_gluten_free' => 1,
            'is_halal' => 0,
            'spice_level' => 2,
        ]);
    }

    // ── #29 Ratings / reviews ──

    /** Guest can submit a review */
    public function test_submit_review_creates_record(): void
    {
        $store = $this->makeRestaurant();
        $menu = $this->makeMenu($store);

        $this->post(route('store.submitReview', $store->slug), [
            'menu_id' => $menu->id,
            'stars' => 4,
            'body' => 'Great food!',
            'reviewer_name' => 'Alice',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('menu_reviews', [
            'menu_id' => $menu->id,
            'store_id' => $store->id,
            'stars' => 4,
            'status' => 'pending', // held for owner approval
        ]);
    }

    /** Duplicate review from same session_hash is rejected */
    public function test_duplicate_review_rejected(): void
    {
        $store = $this->makeRestaurant();
        $menu = $this->makeMenu($store);

        // Submit once
        $first = $this->post(route('store.submitReview', $store->slug), [
            'menu_id' => $menu->id, 'stars' => 5,
        ]);
        $first->assertOk()->assertJson(['success' => true]);
        $token = $first->getCookie('menu_review_token', false)->getValue();

        // Submit again (P6-284: same device cookie + menu_id → same hash)
        $this->withUnencryptedCookie('menu_review_token', $token)->post(route('store.submitReview', $store->slug), [
            'menu_id' => $menu->id, 'stars' => 1,
        ])->assertOk()->assertJson(['success' => false]);

        $this->assertEquals(1, MenuReview::where('menu_id', $menu->id)->count());
    }

    /** A freshly submitted review is pending and does NOT affect rating_avg until approved */
    public function test_submitted_review_is_pending_and_uncounted(): void
    {
        $store = $this->makeRestaurant();
        $menu = $this->makeMenu($store, ['rating_avg' => 0, 'rating_count' => 0]);

        $this->post(route('store.submitReview', $store->slug), [
            'menu_id' => $menu->id, 'stars' => 4,
        ])->assertOk();

        $this->assertDatabaseHas('menu_reviews', ['menu_id' => $menu->id, 'status' => 'pending']);
        // rating unchanged — only approved reviews count (owner approves via the Reviews dashboard)
        $this->assertDatabaseHas('items', ['id' => $menu->id, 'rating_avg' => 0, 'rating_count' => 0]);
    }

    /** Stars clamped to 1–5 */
    public function test_stars_clamped(): void
    {
        $store = $this->makeRestaurant();
        $menu = $this->makeMenu($store);

        $this->post(route('store.submitReview', $store->slug), [
            'menu_id' => $menu->id, 'stars' => 99,
        ])->assertOk();

        $this->assertDatabaseHas('menu_reviews', ['menu_id' => $menu->id, 'stars' => 5]);
    }
}
