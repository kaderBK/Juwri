<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Juwri\Core\Http\Middleware\IsDemoMode;
use Juwri\Core\Models\User;
use Juwri\Modules\AppStore\Models\MarketplaceReview;
use Juwri\Modules\AppStore\Models\ThemePurchase;
use Tests\TestCase;

/**
 * stores P3-1: review WRITE eligibility + one-per-user + pending-until-moderated,
 * for both stores. P6-350: edits, resubmit caps, and aggregate recompute on edit.
 */
class MarketplaceReviewWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paidTheme(): Theme
    {
        $t = Theme::create(['name' => 'Noir', 'slug' => 'noir', 'status' => 'published', 'price' => 19, 'category' => 'fine_dining']);
        $t->versions()->create(['semver' => '1.0.0', 'manifest' => [], 'checksum' => hash('sha256', 'noir'), 'status' => 'published']);

        return $t;
    }

    private function approvedApp(): App
    {
        return App::create(['name' => 'Loyalty', 'slug' => 'loyalty', 'scopes' => ['menu:read'], 'status' => 'approved', 'category' => 'loyalty', 'billing_type' => 'free']);
    }

    private function user(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    // ---- themes ----

    public function test_entitled_user_can_review_theme_pending(): void
    {
        $theme = $this->paidTheme();
        $user = $this->user();
        ThemePurchase::create(['user_id' => $user->id, 'theme_id' => $theme->id, 'price_paid' => 19]);

        $this->actingAs($user)->post(route('marketplace.review', 'noir'), ['rating' => 5, 'title' => 'Great', 'body' => 'Loved it'])
            ->assertRedirect();

        $review = $theme->reviews()->first();
        $this->assertNotNull($review);
        $this->assertSame('pending', $review->status);
        $this->assertSame(5, $review->rating);
        $this->assertSame($user->id, $review->user_id);
    }

    public function test_non_entitled_user_cannot_review_theme(): void
    {
        $this->paidTheme();
        $this->actingAs($this->user())
            ->post(route('marketplace.review', 'noir'), ['rating' => 5, 'body' => 'x'])
            ->assertForbidden();

        $this->assertSame(0, MarketplaceReview::count());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->paidTheme();
        $this->post(route('marketplace.review', 'noir'), ['rating' => 5])->assertRedirect(route('login'));
    }

    // ---- apps ----

    public function test_installed_user_can_review_app_pending(): void
    {
        $app = $this->approvedApp();
        $user = $this->user();
        AppInstallation::create(['app_id' => $app->id, 'user_id' => $user->id, 'granted_scopes' => ['menu:read'], 'status' => 'active', 'installed_at' => now()]);

        $this->actingAs($user)->post(route('apps.store.review', 'loyalty'), ['rating' => 4, 'body' => 'Solid'])
            ->assertRedirect();

        $this->assertSame('pending', $app->reviews()->first()->status);
    }

    public function test_past_install_still_eligible_for_app_review(): void
    {
        $app = $this->approvedApp();
        $user = $this->user();
        AppInstallation::create(['app_id' => $app->id, 'user_id' => $user->id, 'granted_scopes' => ['menu:read'], 'status' => 'uninstalled', 'installed_at' => now()->subDay(), 'uninstalled_at' => now()]);

        $this->actingAs($user)->post(route('apps.store.review', 'loyalty'), ['rating' => 3, 'body' => 'ok'])->assertRedirect();
        $this->assertSame(1, $app->reviews()->count());
    }

    public function test_never_installed_user_cannot_review_app(): void
    {
        $this->approvedApp();
        $this->actingAs($this->user())
            ->post(route('apps.store.review', 'loyalty'), ['rating' => 4, 'body' => 'x'])
            ->assertForbidden();
    }

    // ---- one-per-user + edit ----

    public function test_second_review_edits_the_first_no_duplicate(): void
    {
        $theme = $this->paidTheme();
        $user = $this->user();
        ThemePurchase::create(['user_id' => $user->id, 'theme_id' => $theme->id, 'price_paid' => 19]);

        $this->actingAs($user)->post(route('marketplace.review', 'noir'), ['rating' => 5, 'body' => 'first']);
        $this->actingAs($user)->post(route('marketplace.review', 'noir'), ['rating' => 2, 'body' => 'second']);

        $this->assertSame(1, $theme->reviews()->count());
        $review = $theme->reviews()->first();
        $this->assertSame(2, $review->rating);
        $this->assertSame('second', $review->body);
    }

    public function test_editing_a_published_review_returns_it_to_pending(): void
    {
        $theme = $this->paidTheme();
        $user = $this->user();
        ThemePurchase::create(['user_id' => $user->id, 'theme_id' => $theme->id, 'price_paid' => 19]);
        $theme->reviews()->create(['user_id' => $user->id, 'rating' => 5, 'body' => 'old', 'status' => 'published']);

        $this->actingAs($user)->post(route('marketplace.review', 'noir'), ['rating' => 4, 'body' => 'updated']);

        $review = $theme->reviews()->first();
        $this->assertSame('pending', $review->status);   // re-moderation
        $this->assertSame('updated', $review->body);
    }

    public function test_rating_is_validated(): void
    {
        $theme = $this->paidTheme();
        $user = $this->user();
        ThemePurchase::create(['user_id' => $user->id, 'theme_id' => $theme->id, 'price_paid' => 19]);

        $this->actingAs($user)->post(route('marketplace.review', 'noir'), ['rating' => 9])->assertSessionHasErrors('rating');
        $this->actingAs($user)->post(route('marketplace.review', 'noir'), ['body' => 'no rating'])->assertSessionHasErrors('rating');
    }

    // ---- P6-350: edit of a published review recomputes aggregates immediately ----

    public function test_edit_of_published_review_drops_it_out_of_aggregates_at_once(): void
    {
        $theme = $this->paidTheme();
        $user = $this->user();
        ThemePurchase::create(['user_id' => $user->id, 'theme_id' => $theme->id, 'price_paid' => 19]);

        // Seed: a single published 5-star review with the aggregate already aligned.
        $review = $theme->reviews()->create(['user_id' => $user->id, 'rating' => 5, 'body' => 'loved', 'status' => 'published']);
        MarketplaceReview::recomputeAggregates($theme);
        $theme->refresh();
        $this->assertSame(1, (int) $theme->rating_count);
        $this->assertEquals(5.0, (float) $theme->rating_avg);

        // Edit to 1-star — the row goes back to pending, so it must drop out of the aggregate now.
        $this->actingAs($user)->post(route('marketplace.review', 'noir'), ['rating' => 1, 'body' => 'changed my mind']);

        $review->refresh();
        $this->assertSame('pending', $review->status);
        $this->assertSame(1, $review->rating);

        $theme->refresh();
        $this->assertSame(0, (int) $theme->rating_count, 'pending edit must drop out of rating_count');
        $this->assertEquals(0.0, (float) $theme->rating_avg);
    }

    public function test_edit_sets_review_edited_session_key_for_existing_review(): void
    {
        $theme = $this->paidTheme();
        $user = $this->user();
        ThemePurchase::create(['user_id' => $user->id, 'theme_id' => $theme->id, 'price_paid' => 19]);
        $theme->reviews()->create(['user_id' => $user->id, 'rating' => 4, 'body' => 'old', 'status' => 'published']);

        $this->actingAs($user)
            ->post(route('marketplace.review', 'noir'), ['rating' => 3])
            ->assertRedirect()
            ->assertSessionHas('review_status', 'pending')
            ->assertSessionHas('review_edited', true);
    }

    public function test_first_write_does_not_set_review_edited_session_key(): void
    {
        $theme = $this->paidTheme();
        $user = $this->user();
        ThemePurchase::create(['user_id' => $user->id, 'theme_id' => $theme->id, 'price_paid' => 19]);

        $response = $this->actingAs($user)
            ->post(route('marketplace.review', 'noir'), ['rating' => 5])
            ->assertRedirect()
            ->assertSessionHas('review_status', 'pending');

        // The first write must NOT flash the "Your edit is awaiting moderation" string.
        $this->assertFalse((bool) $response->getSession()->get('review_edited'));
    }

    // ---- P6-350: rejection cooldown + cap ----

    public function test_resubmit_within_24h_of_rejection_is_refused_and_row_unchanged(): void
    {
        $theme = $this->paidTheme();
        $user = $this->user();
        ThemePurchase::create(['user_id' => $user->id, 'theme_id' => $theme->id, 'price_paid' => 19]);

        // Seed a rejected review with rejected_at = 2 hours ago (well inside 24h).
        Carbon::setTestNow('2026-10-19 12:00:00');
        $existing = $theme->reviews()->create([
            'user_id' => $user->id, 'rating' => 4, 'body' => 'will be rejected',
            'status' => 'rejected', 'rejection_count' => 1,
            'rejected_at' => Carbon::now()->subHours(2),
        ]);

        // Author tries to edit/rewrite the rejected review — must be refused.
        Carbon::setTestNow('2026-10-19 13:00:00');
        $this->actingAs($user)
            ->post(route('marketplace.review', 'noir'), ['rating' => 5, 'body' => 'try again'])
            ->assertRedirect()
            ->assertSessionHasErrors('review');

        // Row is unchanged: still rejected, same rejection_count, same rejected_at.
        $existing->refresh();
        $this->assertSame('rejected', $existing->status);
        $this->assertSame(4, $existing->rating);
        $this->assertSame('will be rejected', $existing->body);
        $this->assertSame(1, (int) $existing->rejection_count);
        $this->assertNotNull($existing->rejected_at);
    }

    public function test_resubmit_after_25h_is_accepted_and_returns_to_pending(): void
    {
        $theme = $this->paidTheme();
        $user = $this->user();
        ThemePurchase::create(['user_id' => $user->id, 'theme_id' => $theme->id, 'price_paid' => 19]);

        Carbon::setTestNow('2026-10-18 10:00:00');
        $existing = $theme->reviews()->create([
            'user_id' => $user->id, 'rating' => 2, 'body' => 'first version',
            'status' => 'rejected', 'rejection_count' => 1,
            'rejected_at' => Carbon::now(),
        ]);

        // 25h later, well past the 24h cooldown.
        Carbon::setTestNow('2026-10-19 11:00:00');
        $this->actingAs($user)
            ->post(route('marketplace.review', 'noir'), ['rating' => 4, 'body' => 'fixed it'])
            ->assertRedirect()
            ->assertSessionHas('review_status', 'pending');

        $existing->refresh();
        $this->assertSame('pending', $existing->status);
        $this->assertSame(4, $existing->rating);
        $this->assertSame('fixed it', $existing->body);
        // Cooldown resets the rating/body but keeps rejection_count untouched —
        // so the 3-strikes cap survives across successful resubmits.
        $this->assertSame(1, (int) $existing->rejection_count);
    }

    public function test_fourth_attempt_after_three_rejections_is_refused(): void
    {
        $theme = $this->paidTheme();
        $user = $this->user();
        ThemePurchase::create(['user_id' => $user->id, 'theme_id' => $theme->id, 'price_paid' => 19]);

        // Author has already burned the cap: 3 prior rejections with a fresh rejected_at.
        $existing = $theme->reviews()->create([
            'user_id' => $user->id, 'rating' => 1, 'body' => 'no good',
            'status' => 'rejected', 'rejection_count' => 3,
            'rejected_at' => Carbon::now()->subDays(2),
        ]);

        $this->actingAs($user)
            ->post(route('marketplace.review', 'noir'), ['rating' => 5, 'body' => 'try again please'])
            ->assertRedirect()
            ->assertSessionHasErrors('review');

        $existing->refresh();
        $this->assertSame('rejected', $existing->status);
        $this->assertSame(1, $existing->rating);
        $this->assertSame(3, (int) $existing->rejection_count);
    }

    public function test_pending_review_can_always_be_edited_even_with_a_rejection_history(): void
    {
        $theme = $this->paidTheme();
        $user = $this->user();
        ThemePurchase::create(['user_id' => $user->id, 'theme_id' => $theme->id, 'price_paid' => 19]);

        // Once a rejection has been resubmitted it is `pending` again — the
        // author must be able to keep editing it without another cooldown.
        $existing = $theme->reviews()->create([
            'user_id' => $user->id, 'rating' => 4, 'body' => 'after cooldown',
            'status' => 'pending', 'rejection_count' => 1, 'rejected_at' => Carbon::now()->subDays(2),
        ]);

        $response = $this->actingAs($user)
            ->post(route('marketplace.review', 'noir'), ['rating' => 5])
            ->assertRedirect()
            ->assertSessionHas('review_status', 'pending');

        $existing->refresh();
        $this->assertSame('pending', $existing->status);
        $this->assertSame(5, $existing->rating);
    }

    public function test_admin_reject_stamps_rejected_at_and_increments_counter(): void
    {
        $theme = $this->paidTheme();
        $review = $theme->reviews()->create([
            'user_id' => User::factory()->create()->id, 'rating' => 2, 'body' => 'spam', 'status' => 'pending',
        ]);

        // Sign in as an admin/owner and reject the review.
        $this->withoutMiddleware([
            AdminMiddleware::class,
            IsDemoMode::class,
            Installed::class,
            PreventRequestForgery::class,
        ]);
        $this->actingAs(User::factory()->create(['status' => 1, 'email_verified_at' => now()]));

        Carbon::setTestNow('2026-10-19 12:34:56');
        $this->post(route('admin.reviews.reject', $review->id))->assertRedirect(route('admin.reviews.index'));

        $review->refresh();
        $this->assertSame('rejected', $review->status);
        $this->assertNotNull($review->rejected_at);
        $this->assertSame(1, (int) $review->rejection_count);
        // Same review rejected twice → counter increments, rejected_at updates.
        Carbon::setTestNow('2026-10-19 14:00:00');
        $this->post(route('admin.reviews.reject', $review->id));
        $review->refresh();
        $this->assertSame(2, (int) $review->rejection_count);
    }
}
