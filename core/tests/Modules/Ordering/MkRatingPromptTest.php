<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\AbuseHoldWall;
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
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Marketplace T2-4: post-delivery rating prompt on the order track page.
 *  - Prompt renders ONLY for delivered/completed marketplace orders.
 *  - One review per order (dedupe key menu_reviews.order_id): second visit
 *    shows the thanks state, second POST 422s.
 *  - Write is tenant-scoped (token → order → its store_id) and lands
 *    in the unchanged moderation flow (status=pending).
 */
class MkRatingPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $settings = new class(['theme_color' => '#111111', 'site_title' => 'juwri', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'store_order_whatsapp_message' => 'msg'])
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

        $freePlan = TestPlans::create([
            'name' => 'Free '.Str::random(4), 'description' => 'free', 'monthly_price' => 0,
            'annual_price' => 0, 'lifetime_price' => 0,
            'settings' => ['allow_viewonly' => '1', 'allow_pro' => '1'], 'status' => 1,
        ]);
        config(['settings.free_membership_plan' => $freePlan]);

        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect(), 'plan' => $freePlan,
        ]);

        $this->withoutMiddleware([
            PreventRequestForgery::class,
            ThrottleRequests::class,
            AbuseRateLimit::class,
            AbuseHoldWall::class,
            Installed::class,
            Locale::class,
            LaravelLocalizationRedirectFilter::class,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Grill House', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Shawarma', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return compact('owner', 'r', 'menu');
    }

    private function order(Store $r, Menu $menu, string $status = 'delivered', string $source = 'marketplace'): Order
    {
        $order = Order::create([
            'store_id' => $r->id, 'status' => $status, 'type' => 'takeaway', 'source' => $source,
            'customer_name' => 'Layla', 'track_token' => Str::random(32), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);

        return $order;
    }

    // ------------------------------------------------------------ page render

    public function test_prompt_renders_for_delivered_marketplace_order(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $order = $this->order($r, $menu, 'delivered', 'marketplace');

        $this->get(route('store.orderTrack', $order->track_token))
            ->assertOk()
            ->assertSee('mkRatingPrompt')->assertSee(___('How was your order?'))->assertSeeHtml('<div id="mkRateThanks" hidden>');
    }

    public function test_no_prompt_for_pending_marketplace_order(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $order = $this->order($r, $menu, 'pending', 'marketplace');

        $this->get(route('store.orderTrack', $order->track_token))
            ->assertOk()
            ->assertDontSee('mkRatingPrompt');
    }

    public function test_no_prompt_for_storefront_source_order(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $order = $this->order($r, $menu, 'delivered', 'qr');

        $this->get(route('store.orderTrack', $order->track_token))
            ->assertOk()
            ->assertDontSee('mkRatingPrompt');
    }

    public function test_second_visit_shows_thanks_state(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $order = $this->order($r, $menu);
        MenuReview::create([
            'menu_id' => $menu->id, 'store_id' => $r->id, 'order_id' => $order->id,
            'reviewer_name' => 'Layla', 'stars' => 5, 'status' => 'pending', 'session_hash' => 'x',
        ]);

        $this->get(route('store.orderTrack', $order->track_token))
            ->assertOk()->assertSee(___('Thanks for rating your order!'))->assertSeeHtml('<form id="mkRateForm" hidden>');
    }

    // ------------------------------------------------------------------ write

    public function test_post_creates_pending_tenant_scoped_review(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $order = $this->order($r, $menu);

        $this->postJson(route('store.orderTrackReview', $order->track_token), [
            'stars' => 4, 'body' => 'Great food',
        ])->assertOk()->assertJsonPath('success', true);

        $review = MenuReview::where('order_id', $order->id)->first();
        $this->assertNotNull($review);
        // Tenant scoping: the row carries the ORDER's restaurant, never a caller-chosen one.
        $this->assertSame($r->id, (int) $review->store_id);
        $this->assertSame($menu->id, (int) $review->menu_id); // first line item anchor
        $this->assertSame(4, (int) $review->stars);
        $this->assertSame('Great food', $review->body);
        $this->assertSame('Layla', $review->reviewer_name);
        $this->assertSame('pending', $review->status); // moderation flow unchanged
    }

    public function test_second_post_is_rejected_one_per_order(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $order = $this->order($r, $menu);

        $this->postJson(route('store.orderTrackReview', $order->track_token), ['stars' => 5])->assertOk();
        $this->postJson(route('store.orderTrackReview', $order->track_token), ['stars' => 1])
            ->assertStatus(422);

        $this->assertSame(1, MenuReview::where('order_id', $order->id)->count());
    }

    public function test_stars_validation(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $order = $this->order($r, $menu);

        $this->postJson(route('store.orderTrackReview', $order->track_token), ['stars' => 6])->assertStatus(422);
        $this->postJson(route('store.orderTrackReview', $order->track_token), ['stars' => 0])->assertStatus(422);
        $this->postJson(route('store.orderTrackReview', $order->track_token), [])->assertStatus(422);
        $this->assertSame(0, MenuReview::where('order_id', $order->id)->count());
    }

    public function test_post_rejected_for_undelivered_or_storefront_orders(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();

        $pending = $this->order($r, $menu, 'pending', 'marketplace');
        $this->postJson(route('store.orderTrackReview', $pending->track_token), ['stars' => 5])
            ->assertStatus(422);

        $storefront = $this->order($r, $menu, 'delivered', 'qr');
        $this->postJson(route('store.orderTrackReview', $storefront->track_token), ['stars' => 5])
            ->assertStatus(422);

        $this->assertSame(0, MenuReview::count());
    }

    public function test_completed_marketplace_order_can_be_reviewed(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $order = $this->order($r, $menu, 'completed', 'marketplace');

        $this->postJson(route('store.orderTrackReview', $order->track_token), ['stars' => 3])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, MenuReview::where('order_id', $order->id)->count());
    }
}
