<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\MenuReview;
use App\Models\StoreReview;
use App\Services\Directory\StoreRatingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DIRECTORY T2-4: StoreRatingService — the ONE source for a store's public
 * rating. D3's no-blend rule (store_reviews only, MenuReview never mixes in)
 * is the load-bearing assertion in this file.
 */
class DirectoryStoreRatingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1, 2, 3, 5); // P1-04 5b: fixture store ids must be real stores
    }

    use RefreshDatabase;

    public function test_forStore_computes_avg_count_and_histogram(): void
    {
        StoreReview::factory()->approved()->forStore(1)->create(['stars' => 5]);
        StoreReview::factory()->approved()->forStore(1)->create(['stars' => 5]);
        StoreReview::factory()->approved()->forStore(1)->create(['stars' => 3]);

        $rating = StoreRatingService::forStore(1);

        $this->assertSame(3, $rating['count']);
        $this->assertEquals(4.3, $rating['avg']);
        $this->assertSame(2, $rating['histogram'][5]);
        $this->assertSame(1, $rating['histogram'][3]);
        $this->assertSame(0, $rating['histogram'][1]);
    }

    public function test_pending_and_rejected_reviews_are_excluded(): void
    {
        StoreReview::factory()->approved()->forStore(1)->create(['stars' => 5]);
        StoreReview::factory()->forStore(1)->create(['stars' => 1]); // pending
        StoreReview::factory()->forStore(1)->create(['stars' => 1, 'status' => 'rejected']);

        $rating = StoreRatingService::forStore(1);

        $this->assertSame(1, $rating['count']);
        $this->assertEquals(5.0, $rating['avg']);
    }

    public function test_a_store_with_no_reviews_returns_a_null_average(): void
    {
        $rating = StoreRatingService::forStore(999);

        $this->assertNull($rating['avg']);
        $this->assertSame(0, $rating['count']);
    }

    /** D3: the directory rating is store_reviews ONLY — MenuReview never blends in. */
    public function test_menu_review_item_ratings_never_blend_into_the_store_rating(): void
    {
        // A restaurant with only 1-star ITEM reviews (MenuReview) and 5-star
        // STORE reviews. If the two ever blended, the average would drop.
        MenuReview::create(['menu_id' => 1, 'store_id' => 1, 'stars' => 1, 'status' => 'approved']);
        MenuReview::create(['menu_id' => 2, 'store_id' => 1, 'stars' => 1, 'status' => 'approved']);
        StoreReview::factory()->approved()->forStore(1)->create(['stars' => 5]);

        $rating = StoreRatingService::forStore(1);

        $this->assertSame(1, $rating['count'], 'MenuReview rows must not be counted');
        $this->assertEquals(5.0, $rating['avg'], 'MenuReview stars must never lower the store rating');
    }

    public function test_batch_returns_every_store_with_a_single_grouped_query(): void
    {
        StoreReview::factory()->approved()->forStore(1)->create(['stars' => 4]);
        StoreReview::factory()->approved()->forStore(2)->create(['stars' => 2]);
        StoreReview::factory()->approved()->forStore(2)->create(['stars' => 4]);
        // store 3 has no reviews at all.

        DB::enableQueryLog();
        $ratings = StoreRatingService::batch([1, 2, 3]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries, 'a card grid must never run one query per card');
        $this->assertEquals(4.0, $ratings[1]['avg']);
        $this->assertEquals(3.0, $ratings[2]['avg']);
        $this->assertSame(0, $ratings[3]['count']);
    }

    public function test_batch_reuses_an_already_warm_cache_without_requerying(): void
    {
        StoreReview::factory()->approved()->forStore(1)->create(['stars' => 5]);
        StoreRatingService::forStore(1); // warms the cache

        DB::enableQueryLog();
        StoreRatingService::batch([1]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries, 'an already-cached store must not be re-queried');
    }

    public function test_bust_clears_the_cached_value(): void
    {
        StoreReview::factory()->approved()->forStore(1)->create(['stars' => 5]);
        $this->assertSame(1, StoreRatingService::forStore(1)['count']);

        StoreReview::factory()->approved()->forStore(1)->create(['stars' => 1]);
        // Still cached — the new review is invisible until busted.
        $this->assertSame(1, StoreRatingService::forStore(1)['count']);

        StoreRatingService::bust(1);
        $this->assertSame(2, StoreRatingService::forStore(1)['count']);
    }
}
