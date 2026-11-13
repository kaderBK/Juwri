<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Customers\Models\CustomerFeedback;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * K1 — post-payment review funnel. The tokened endpoint returns the shipped
 * FeedbackService decision: ≥4★ → public_review + the store's Google URL, ≤3★ →
 * private + null (never routed to Google). Self-gates on google_review_url.
 */
class OrderReviewFunnelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_support_desk' => true]);
        $this->withoutMiddleware([
            PreventRequestForgery::class,
            ThrottleRequests::class,
            AbuseRateLimit::class,
            Installed::class,
        ]);
    }

    private function orderWithReviewUrl(?string $reviewUrl = 'https://g.page/r/abc/review'): Order
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementWriter::grant($owner, 'support_desk', 'admin', null, '1', null, 'test');
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        if ($reviewUrl !== null) {
            StoreOption::updateStoreOption($r->id, 'google_review_url', $reviewUrl);
        }

        return Order::create([
            'store_id' => $r->id, 'type' => 'takeaway', 'customer_name' => 'Jane',
            'phone_number' => '555', 'status' => 'completed', 'seen' => 1,
            'track_token' => Str::random(32), 'created_at' => now(),
        ]);
    }

    public function test_high_rating_routes_to_google(): void
    {
        $order = $this->orderWithReviewUrl('https://g.page/r/abc/review');

        $this->postJson(route('store.orderReviewFunnel', $order->track_token), ['rating' => 5])
            ->assertOk()
            ->assertJson(['success' => true, 'funnel' => 'public_review', 'review_url' => 'https://g.page/r/abc/review']);
    }

    public function test_low_rating_stays_private_and_persists_comment(): void
    {
        $order = $this->orderWithReviewUrl();

        $this->postJson(route('store.orderReviewFunnel', $order->track_token), ['rating' => 2, 'comment' => 'Too slow'])
            ->assertOk()
            ->assertJson(['success' => true, 'funnel' => 'private', 'review_url' => null]);

        $fb = CustomerFeedback::where('order_id', $order->id)->first();
        $this->assertNotNull($fb);
        $this->assertSame(2, (int) $fb->rating);
        $this->assertSame('Too slow', $fb->comment);
    }

    public function test_no_review_url_configured_is_rejected(): void
    {
        $order = $this->orderWithReviewUrl(null); // no google_review_url

        $this->postJson(route('store.orderReviewFunnel', $order->track_token), ['rating' => 5])
            ->assertStatus(422);
    }
}
