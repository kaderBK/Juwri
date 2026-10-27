<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\App;
use Juwri\Modules\AppStore\Models\MarketplaceReview;
use App\Models\Theme;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * stores P3-2: moderation queue decisions and the denormalized rating aggregates
 * they recompute (published reviews only).
 */
class AdminMarketplaceReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\AdminMiddleware::class,
            \Juwri\Core\Http\Middleware\IsDemoMode::class,
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
        $this->actingAs(User::factory()->create(['status' => 1, 'email_verified_at' => now()]));
    }

    private function theme(): Theme
    {
        return Theme::create(['name' => 'Noir', 'slug' => 'noir', 'status' => 'published', 'price' => 19]);
    }

    private function review(Theme $theme, int $rating, string $status = 'pending'): MarketplaceReview
    {
        return $theme->reviews()->create([
            'user_id' => User::factory()->create()->id, 'rating' => $rating, 'body' => "r{$rating}", 'status' => $status,
        ]);
    }

    public function test_approve_publishes_and_updates_aggregate(): void
    {
        $theme = $this->theme();
        $r = $this->review($theme, 5);

        $this->post(route('admin.reviews.approve', $r->id))->assertRedirect(route('admin.reviews.index'));

        $this->assertSame('published', $r->fresh()->status);
        $theme->refresh();
        $this->assertSame(1, (int) $theme->rating_count);
        $this->assertEquals(5.0, (float) $theme->rating_avg);
    }

    public function test_aggregate_averages_published_only(): void
    {
        $theme = $this->theme();
        $a = $this->review($theme, 5);
        $b = $this->review($theme, 4);
        $c = $this->review($theme, 1);

        $this->post(route('admin.reviews.approve', $a->id));
        $this->post(route('admin.reviews.approve', $b->id));
        $this->post(route('admin.reviews.reject', $c->id));      // rejected → excluded

        $theme->refresh();
        $this->assertSame(2, (int) $theme->rating_count);
        $this->assertEquals(4.5, (float) $theme->rating_avg);
    }

    public function test_rejecting_a_published_review_recomputes_down(): void
    {
        $theme = $this->theme();
        $a = $this->review($theme, 5, 'published');
        $b = $this->review($theme, 3, 'published');
        MarketplaceReview::recomputeAggregates($theme);
        $theme->refresh();
        $this->assertSame(2, (int) $theme->rating_count);
        $this->assertEquals(4.0, (float) $theme->rating_avg);

        $this->post(route('admin.reviews.reject', $a->id));      // pull the 5 back out

        $theme->refresh();
        $this->assertSame(1, (int) $theme->rating_count);
        $this->assertEquals(3.0, (float) $theme->rating_avg);
    }

    public function test_zero_published_resets_aggregate(): void
    {
        $theme = $this->theme();
        $r = $this->review($theme, 4, 'published');
        MarketplaceReview::recomputeAggregates($theme);

        $this->post(route('admin.reviews.reject', $r->id));

        $theme->refresh();
        $this->assertSame(0, (int) $theme->rating_count);
        $this->assertEquals(0.0, (float) $theme->rating_avg);
    }

    public function test_aggregate_works_for_apps_too(): void
    {
        $app = App::create(['name' => 'Loyalty', 'slug' => 'loyalty', 'scopes' => ['menu:read'], 'status' => 'approved', 'billing_type' => 'free']);
        $r = $app->reviews()->create(['user_id' => User::factory()->create()->id, 'rating' => 4, 'body' => 'ok', 'status' => 'pending']);

        $this->post(route('admin.reviews.approve', $r->id));

        $app->refresh();
        $this->assertSame(1, (int) $app->rating_count);
        $this->assertEquals(4.0, (float) $app->rating_avg);
    }
}
