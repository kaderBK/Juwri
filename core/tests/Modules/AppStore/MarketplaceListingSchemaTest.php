<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\App;
use Juwri\Modules\AppStore\Models\MarketplaceReview;
use App\Models\Theme;
use Juwri\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * stores P1-3: the listing-metadata schema — new storefront columns on
 * themes/apps and the two polymorphic tables (media + reviews) with the
 * one-review-per-user-per-listing constraint both stores rely on.
 */
class MarketplaceListingSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeTheme(array $overrides = []): Theme
    {
        return Theme::create(array_merge([
            'name'        => 'Cafe Warm',
            'slug'        => 'cafe-warm',
            'status'      => 'published',
            'price'       => 19.00,
            'category'    => 'cafe',
            'tagline'     => 'A warm cafe theme',
            'support_url' => 'https://support.test/cafe',
        ], $overrides));
    }

    private function makeApp(array $overrides = []): App
    {
        return App::create(array_merge([
            'name'        => 'Loyalty Plus',
            'slug'        => 'loyalty-plus',
            'scopes'      => ['orders:read'],
            'status'      => 'approved',
            'category'    => 'loyalty',
            'tagline'     => 'Points and rewards',
            'support_url' => 'https://support.test/loyalty',
            'docs_url'    => 'https://docs.test/loyalty',
        ], $overrides));
    }

    public function test_theme_listing_columns_and_relations(): void
    {
        $theme = $this->makeTheme();
        $theme->media()->create(['disk_path' => 'marketplace/theme/1/a.png', 'position' => 1, 'kind' => 'screenshot']);
        $theme->media()->create(['disk_path' => 'marketplace/theme/1/b.png', 'position' => 0, 'kind' => 'screenshot']);
        $theme->media()->create(['disk_path' => 'marketplace/theme/1/banner.png', 'position' => 0, 'kind' => 'banner']);

        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $theme->reviews()->create(['user_id' => $user->id, 'rating' => 5, 'title' => 'Great', 'body' => 'Loved it', 'status' => 'published']);

        $fresh = $theme->fresh();
        $this->assertSame('cafe', $fresh->category);
        $this->assertSame('A warm cafe theme', $fresh->tagline);
        $this->assertSame(0.0, (float) $fresh->rating_avg);
        $this->assertSame(0, (int) $fresh->rating_count);

        $this->assertCount(3, $theme->media);
        $this->assertCount(2, $theme->screenshots);           // banner excluded
        $this->assertSame(0, $theme->screenshots()->first()->position); // ordered by position
        $this->assertCount(1, $theme->reviews);
    }

    public function test_app_listing_columns_and_relations(): void
    {
        $app = $this->makeApp();
        $app->media()->create(['disk_path' => 'marketplace/app/1/a.png', 'position' => 0, 'kind' => 'screenshot']);

        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $app->reviews()->create(['user_id' => $user->id, 'rating' => 4, 'body' => 'Solid', 'status' => 'pending']);

        $fresh = $app->fresh();
        $this->assertSame('loyalty', $fresh->category);
        $this->assertSame('https://docs.test/loyalty', $fresh->docs_url);
        $this->assertSame(0, (int) $fresh->rating_count);

        $this->assertCount(1, $app->media);
        $this->assertCount(1, $app->reviews);
        $this->assertInstanceOf(App::class, $app->reviews->first()->reviewable);
    }

    public function test_one_review_per_user_per_listing(): void
    {
        $theme = $this->makeTheme();
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $theme->reviews()->create(['user_id' => $user->id, 'rating' => 5, 'body' => 'First', 'status' => 'published']);

        $this->expectException(QueryException::class);
        $theme->reviews()->create(['user_id' => $user->id, 'rating' => 3, 'body' => 'Second', 'status' => 'pending']);
    }

    public function test_same_user_may_review_two_different_listings(): void
    {
        $theme = $this->makeTheme();
        $app = $this->makeApp();
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $theme->reviews()->create(['user_id' => $user->id, 'rating' => 5, 'body' => 'theme', 'status' => 'published']);
        $app->reviews()->create(['user_id' => $user->id, 'rating' => 4, 'body' => 'app', 'status' => 'published']);

        // Polymorphic type is part of the unique key — no collision across stores.
        $this->assertSame(2, MarketplaceReview::where('user_id', $user->id)->count());
    }
}
