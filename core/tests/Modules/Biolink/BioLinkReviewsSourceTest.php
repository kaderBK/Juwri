<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\StoreReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * BIOLINK-TPL T2-6 (A7): a reviews block with config.source=live renders the store's
 * live rating/count (StoreRatingService); manual keeps the owner-typed fields.
 * reviewDefaultSource() picks live when the store already has ratings, else manual.
 */
class BioLinkReviewsSourceTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    private function rating(Store $store, array $stars): void
    {
        foreach ($stars as $s) {
            StoreReview::factory()->forStore($store->id)->approved()->create(['stars' => $s]);
        }
    }

    public function test_live_source_pulls_store_rating_and_count(): void
    {
        $store = Store::factory()->create();
        $this->rating($store, [5, 5, 4]);   // avg 4.7, count 3

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'reviews', 'label' => 'Reviews',
            'config' => ['source' => 'live', 'rating' => '1.0', 'count' => '1'], // stale manual numbers
        ]);

        $cfg = $this->svc()->blocksFor($store->id)[0]['config'];

        $this->assertSame('3', $cfg['count']);         // live overrides stale manual
        $this->assertSame('4.7', $cfg['rating']);
    }

    public function test_manual_source_keeps_owner_typed_fields(): void
    {
        $store = Store::factory()->create();
        $this->rating($store, [5, 5, 5]);   // live data exists but must be ignored

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'reviews', 'label' => 'Reviews',
            'config' => ['source' => 'manual', 'rating' => '4.2', 'count' => '128'],
        ]);

        $cfg = $this->svc()->blocksFor($store->id)[0]['config'];

        $this->assertSame('4.2', $cfg['rating']);
        $this->assertSame('128', $cfg['count']);
    }

    public function test_live_source_with_no_reviews_empties_the_fields(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'reviews', 'label' => 'Reviews',
            'config' => ['source' => 'live'],
        ]);

        $cfg = $this->svc()->blocksFor($store->id)[0]['config'];

        // No ratings → empty rating/count, so the blade guard hides the card.
        $this->assertSame('', $cfg['rating']);
        $this->assertSame('', $cfg['count']);
    }

    public function test_review_default_source_is_live_only_when_data_exists(): void
    {
        $withData = Store::factory()->create();
        $this->rating($withData, [4, 5]);
        $empty = Store::factory()->create();

        $this->assertSame('live', $this->svc()->reviewDefaultSource($withData->id));
        $this->assertSame('manual', $this->svc()->reviewDefaultSource($empty->id));
    }
}
