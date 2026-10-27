<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\MenuReview;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-283 / P6-284 / P6-285 — review moderation edge cases:
 *   - status must be validated (no silent default to approved),
 *   - the menu-item rating must collapse to 0 / 0 once every review is rejected,
 *   - a 400-character Arabic review body must round-trip whole (mb_substr,
 *     never byte-sliced),
 *   - two guest reviewers sharing one IP / UA but with different device
 *     tokens may both leave one review each, and the same token a second
 *     time is blocked,
 *   - and an order-anchored review contributes to the store-level rating
 *     via Store::storeRating() but NEVER pulls Menu.rating_avg down.
 */
class ReviewModerationP6283Test extends TestCase
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
            ThrottleRequests::class,
            AbuseRateLimit::class,
            AbuseHoldWall::class,
            EncryptCookies::class,
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

        return Menu::create(array_merge(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10.00, 'active' => 1, 'rating_avg' => 0, 'rating_count' => 0], $attrs));
    }

    private function makeItemReview(Store $store, Menu $item, array $attrs = []): MenuReview
    {
        return MenuReview::create(array_merge([
            'store_id' => $store->id,
            'menu_id' => $item->id,
            'stars' => 4,
            'body' => 'Great',
            'status' => 'pending',
            'session_hash' => Str::random(32),
        ], $attrs));
    }

    // 1. P6-283 — missing / unknown status must NOT silently default to approved.

    public function test_missing_status_returns_422(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);
        $review = $this->makeItemReview($store, $item);

        $this->actingAs($owner)
            ->postJson(route('dashboard.reviews.update', [$store->id, $review->id]), [])
            ->assertStatus(422);

        $this->assertDatabaseHas('menu_reviews', ['id' => $review->id, 'status' => 'pending']);
    }

    public function test_unknown_status_returns_422(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);
        $review = $this->makeItemReview($store, $item);

        $this->actingAs($owner)
            ->postJson(route('dashboard.reviews.update', [$store->id, $review->id]), ['status' => 'banana'])
            ->assertStatus(422);

        $this->assertDatabaseHas('menu_reviews', ['id' => $review->id, 'status' => 'pending']);
    }

    // 2. P6-283 — when the only approved review is rejected, the item's
    //    rating collapses to (0, 0), not (null, 0) and not (1, 1).

    public function test_reject_only_approved_review_zeroes_item_rating(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);
        $review = $this->makeItemReview($store, $item, ['stars' => 5, 'status' => 'approved']);

        $this->actingAs($owner)
            ->postJson(route('dashboard.reviews.update', [$store->id, $review->id]), ['status' => 'rejected'])
            ->assertOk();

        $item->refresh();
        $this->assertSame(0, (int) $item->rating_count);
        $this->assertSame(0.0, round((float) $item->rating_avg, 2));
        $this->assertDatabaseHas('items', ['id' => $item->id, 'rating_avg' => 0.00, 'rating_count' => 0]);
    }

    // 3. P6-284 — an Arabic body of 400 letters must round-trip whole.

    public function test_400_arabic_letter_body_is_stored_whole(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);

        // 400 Arabic letters (each 2 bytes in UTF-8 → 800 bytes, so byte-slice
        // would corrupt at index 500 by cutting inside a letter).
        $body = str_repeat('ش', 400);

        $this->postJson(route('store.submitReview', $store->slug), [
            'menu_id' => $item->id,
            'stars' => 5,
            'body' => $body,
            'reviewer_name' => 'Layla',
        ])->assertOk()->assertJson(['success' => true]);

        $row = MenuReview::where('menu_id', $item->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(400, mb_strlen($row->body, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($row->body, 'UTF-8'));
        // The 400-char string is below the 500 limit, so it passes through whole.
        $this->assertSame($body, $row->body);
    }

    // 4. P6-284 — two guests sharing one IP / UA but with different device
    //    cookies both succeed; same cookie a second time is rejected.

    public function test_two_guests_same_ip_ua_different_cookies_both_succeed_same_cookie_twice_blocked(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);

        // Guest A: no cookie yet → server mints one and attaches it.
        $respA = $this->post(route('store.submitReview', $store->slug), [
            'menu_id' => $item->id,
            'stars' => 5,
            'reviewer_name' => 'Guest A',
        ], ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'CommonUA/1.0']);
        $respA->assertOk()->assertJson(['success' => true]);
        $cookieA = $respA->getCookie('menu_review_token', false);
        $this->assertNotNull($cookieA, 'server must mint a long-rolling review cookie for guest diners');
        $this->assertNotSame('', (string) $cookieA->getValue());

        // Same cookie again → blocked, no second row.
        $respA2 = $this->withUnencryptedCookie('menu_review_token', $cookieA->getValue())
            ->post(route('store.submitReview', $store->slug), [
                'menu_id' => $item->id,
                'stars' => 1,
                'reviewer_name' => 'Guest A trying again',
            ], ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'CommonUA/1.0']);
        $respA2->assertOk()->assertJson(['success' => false]);

        // Guest B: different IP+UA would also work, but the requirement is that
        // SAME IP+UA + DIFFERENT cookie also works. Send the original IP/UA
        // with a fresh cookie value.
        $respB = $this->withUnencryptedCookie('menu_review_token', bin2hex(random_bytes(32)))
            ->post(route('store.submitReview', $store->slug), [
                'menu_id' => $item->id,
                'stars' => 4,
                'reviewer_name' => 'Guest B',
            ], ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'CommonUA/1.0']);
        $respB->assertOk()->assertJson(['success' => true]);

        $this->assertSame(2, MenuReview::where('menu_id', $item->id)->count(), 'two distinct cookies, two reviews — even with identical IP + UA');
    }

    // 5. P6-285 — an order-anchored review must not pull Menu.rating_avg down,
    //    and the store-level Store::storeRating() must reflect it.

    public function test_order_anchored_review_does_not_change_item_rating_but_changes_store_rating(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store, ['rating_avg' => 0, 'rating_count' => 0]);

        // Marketplace order with this item as its only line.
        $order = Order::create([
            'store_id' => $store->id, 'status' => 'delivered', 'source' => 'marketplace',
            'customer_name' => 'Layla', 'track_token' => Str::random(32), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);

        // Order-anchored review — menu_id is set (FK anchor on the first
        // line item) but order_id is NOT NULL: this MUST be excluded from
        // the item rating recompute done in updateReview().
        $orderReview = MenuReview::create([
            'menu_id' => $item->id,
            'store_id' => $store->id,
            'order_id' => $order->id,
            'stars' => 1,
            'body' => 'Late and cold',
            'status' => 'pending',
            'session_hash' => Str::random(32),
        ]);

        $this->actingAs($owner)
            ->postJson(route('dashboard.reviews.update', [$store->id, $orderReview->id]), ['status' => 'approved'])
            ->assertOk();

        $item->refresh();
        // Item rating must NOT include the order review → 0 / 0 still.
        $this->assertSame(0, (int) $item->rating_count, 'order-anchored review must not count toward the item');
        $this->assertSame(0.0, round((float) $item->rating_avg, 2), 'item average stays at 0');

        // Store rating helper does see it.
        $store = $store->fresh();
        $sr = $store->storeRating();
        $this->assertSame(1, (int) $sr['count'], 'store rating counts the approved order-anchored review');
        $this->assertSame(1.0, round((float) $sr['avg'], 2), 'one ★-only review → store avg = 1');
    }
}
