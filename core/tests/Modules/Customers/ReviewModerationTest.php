<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

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

/**
 * #29 Review moderation — owner can approve/reject guest reviews via Nova dashboard.
 * When approved, rating_avg and rating_count on the Menu item recalculate.
 */
class ReviewModerationTest extends TestCase
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
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeItem(Store $store, array $attrs = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create(array_merge(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10.00, 'active' => 1], $attrs));
    }

    private function makeReview(Store $store, Menu $item, int $stars = 4, string $status = 'pending'): MenuReview
    {
        return MenuReview::create([
            'store_id' => $store->id,
            'menu_id' => $item->id,
            'stars' => $stars,
            'body' => 'Great',
            'status' => $status,
            'session_hash' => Str::random(32),
        ]);
    }

    /** The moderation list renders the review text (body column, not a phantom "comment") */
    public function test_reviews_page_shows_review_body(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);
        $this->makeReview($store, $item);

        $this->actingAs($owner)
            ->get(route('dashboard.reviews', $store->id))
            ->assertOk()
            ->assertSee('Great');
    }

    /** Owner can approve a pending review */
    public function test_owner_approves_review(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);
        $review = $this->makeReview($store, $item);

        $this->actingAs($owner)
            ->post(route('dashboard.reviews.update', [$store->id, $review->id]), ['status' => 'approved'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('menu_reviews', ['id' => $review->id, 'status' => 'approved']);
    }

    /** Owner can reject a pending review */
    public function test_owner_rejects_review(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);
        $review = $this->makeReview($store, $item);

        $this->actingAs($owner)
            ->post(route('dashboard.reviews.update', [$store->id, $review->id]), ['status' => 'rejected'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('menu_reviews', ['id' => $review->id, 'status' => 'rejected']);
    }

    /** Approving a review recalculates rating_avg and rating_count on menu item */
    public function test_approve_recalculates_rating_avg(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);

        // Two pending reviews
        $r1 = $this->makeReview($store, $item, 4);
        $r2 = $this->makeReview($store, $item, 2);

        // Approve both
        $this->actingAs($owner)->post(route('dashboard.reviews.update', [$store->id, $r1->id]), ['status' => 'approved']);
        $this->actingAs($owner)->post(route('dashboard.reviews.update', [$store->id, $r2->id]), ['status' => 'approved']);

        // avg = (4+2)/2 = 3.0, count = 2
        $this->assertDatabaseHas('items', ['id' => $item->id, 'rating_avg' => 3.00, 'rating_count' => 2]);
    }

    /** Rejecting a review excludes it from rating_avg */
    public function test_reject_excludes_from_rating_avg(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);

        $r1 = $this->makeReview($store, $item, 5);
        $r2 = $this->makeReview($store, $item, 1);

        $this->actingAs($owner)->post(route('dashboard.reviews.update', [$store->id, $r1->id]), ['status' => 'approved']);
        $this->actingAs($owner)->post(route('dashboard.reviews.update', [$store->id, $r2->id]), ['status' => 'rejected']);

        // Only r1 approved → avg=5, count=1
        $this->assertDatabaseHas('items', ['id' => $item->id, 'rating_avg' => 5.00, 'rating_count' => 1]);
    }

    /** Tenant isolation: other owner cannot moderate reviews */
    public function test_tenant_isolation_review_moderation(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);
        $review = $this->makeReview($store, $item);

        $this->actingAs($other)
            ->post(route('dashboard.reviews.update', [$store->id, $review->id]), ['status' => 'approved'])
            ->assertNotFound(); // Post::where('user_id', auth()->id()) fails → 404

        $this->assertDatabaseHas('menu_reviews', ['id' => $review->id, 'status' => 'pending']);
    }
}
