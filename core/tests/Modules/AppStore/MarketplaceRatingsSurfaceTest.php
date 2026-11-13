<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\App;
use App\Models\Theme;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * stores P3-3: the ratings surface — min-rating filter and rating sort on browse,
 * and the published-only review list + distribution on detail.
 */
class MarketplaceRatingsSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
    }

    private function theme(string $slug, float $avg, int $count = 3): Theme
    {
        $t = Theme::create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => 'published', 'price' => 0, 'category' => 'cafe', 'rating_avg' => $avg, 'rating_count' => $count]);
        $t->versions()->create(['semver' => '1.0.0', 'manifest' => [], 'checksum' => hash('sha256', $slug), 'status' => 'published']);

        return $t;
    }

    public function test_min_rating_filters_themes(): void
    {
        $this->theme('five', 5.0);
        $this->theme('three', 3.0);
        $this->theme('one', 1.0);

        $this->assertEqualsCanonicalizing(['five'], $this->get(route('marketplace.index', ['min_rating' => 4]))->viewData('results')->pluck('slug')->all());
        $this->assertEqualsCanonicalizing(['five', 'three'], $this->get(route('marketplace.index', ['min_rating' => 3]))->viewData('results')->pluck('slug')->all());
        $this->assertSame(3, $this->get(route('marketplace.index', ['min_rating' => 0]))->viewData('total'));
    }

    public function test_sort_top_rated_orders_themes(): void
    {
        $this->theme('mid', 3.5);
        $this->theme('top', 4.9);
        $this->theme('low', 2.1);

        $order = $this->get(route('marketplace.index', ['sort' => 'top_rated']))->viewData('results')->pluck('slug')->all();
        $this->assertSame(['top', 'mid', 'low'], $order);
    }

    public function test_min_rating_filters_apps(): void
    {
        App::create(['name' => 'Hi', 'slug' => 'hi', 'scopes' => ['menu:read'], 'status' => 'approved', 'billing_type' => 'free', 'rating_avg' => 4.5, 'rating_count' => 2]);
        App::create(['name' => 'Lo', 'slug' => 'lo', 'scopes' => ['menu:read'], 'status' => 'approved', 'billing_type' => 'free', 'rating_avg' => 2.0, 'rating_count' => 1]);

        $this->assertSame(['hi'], $this->get(route('apps.store.index', ['min_rating' => 4]))->viewData('results')->pluck('slug')->all());
    }

    public function test_detail_lists_published_reviews_and_distribution(): void
    {
        $theme = $this->theme('noir', 0, 0);
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $theme->reviews()->create(['user_id' => $u1->id, 'rating' => 5, 'body' => 'excellent', 'status' => 'published']);
        $theme->reviews()->create(['user_id' => $u2->id, 'rating' => 3, 'body' => 'okay', 'status' => 'published']);
        $theme->reviews()->create(['user_id' => User::factory()->create()->id, 'rating' => 1, 'body' => 'hidden pending', 'status' => 'pending']);

        $res = $this->get(route('marketplace.show', 'noir'))->assertOk();
        $reviews = $res->viewData('publishedReviews');
        $this->assertCount(2, $reviews);                                  // pending excluded
        $this->assertTrue($reviews->pluck('body')->contains('excellent'));
        $this->assertFalse($reviews->pluck('body')->contains('hidden pending'));

        $dist = $res->viewData('distribution');
        $this->assertSame(1, $dist[5]);
        $this->assertSame(1, $dist[3]);
        $this->assertSame(0, $dist[4]);
    }

    public function test_review_body_is_escaped_on_public_detail(): void
    {
        $theme = $this->theme('xss', 0, 0);
        $theme->reviews()->create(['user_id' => User::factory()->create()->id, 'rating' => 5, 'body' => '<script>alert(1)</script>', 'status' => 'published']);
        \Juwri\Modules\AppStore\Models\MarketplaceReview::recomputeAggregates($theme); // publish keeps count in sync

        $html = $this->get(route('marketplace.show', 'xss'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
