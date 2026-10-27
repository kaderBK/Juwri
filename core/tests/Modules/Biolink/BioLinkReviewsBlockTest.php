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
 * bio-t15 Lane A / A4: the `reviews` block (SPEC §2 A4) enhances the
 * pre-existing `reviews` type in place with `platform` and `show_stars`, plus
 * a distinct "Leave a review" CTA to `url`. The pre-existing T2-6
 * source=live/manual resolution (BioLinkService::resolveReviewBlocks) is
 * untouched and still runs before this partial renders.
 */
class BioLinkReviewsBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    private function render(Store $store): string
    {
        $row = $this->svc()->blocksFor($store->id)[0];

        return (string) view('bio._blocks.reviews', ['block' => $row])->render();
    }

    public function test_renders_ssr_stars_platform_and_leave_a_review_cta(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'reviews', 'label' => 'Guests say', 'url' => null,
            'config' => ['platform' => 'google', 'url' => 'https://g.example/review', 'rating' => '4.6', 'count' => '212', 'show_stars' => true],
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString('★★★★★', $html); // round(4.6) = 5 full stars
        $this->assertStringContainsString('Google', $html);
        $this->assertStringContainsString('4.6', $html);
        $this->assertStringContainsString('212', $html);
        $this->assertStringContainsString('href="https://g.example/review"', $html);
        $this->assertStringContainsString('Leave a review', $html);
    }

    public function test_show_stars_false_hides_the_star_row_but_keeps_the_number(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'reviews',
            'config' => ['rating' => '4.2', 'count' => '10', 'show_stars' => false],
        ]);

        $html = $this->render($store);

        $this->assertStringNotContainsString('★', $html);
        $this->assertStringContainsString('4.2', $html);
    }

    public function test_no_url_renders_no_cta(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'reviews', 'url' => null,
            'config' => ['rating' => '4.0', 'count' => '5'],
        ]);

        $html = $this->render($store);

        $this->assertStringNotContainsString('Leave a review', $html);
    }

    public function test_live_source_still_resolves_before_platform_config_renders(): void
    {
        $store = Store::factory()->create();
        StoreReview::factory()->forStore($store->id)->approved()->create(['stars' => 5]);
        StoreReview::factory()->forStore($store->id)->approved()->create(['stars' => 5]);

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'reviews',
            'config' => ['source' => 'live', 'platform' => 'yelp', 'rating' => '1.0', 'count' => '1'],
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString('5', $html); // live count overrode the stale manual '1'
        $this->assertStringContainsString('Yelp', $html);
    }

    public function test_no_rating_or_count_degrades_to_nothing(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'reviews', 'config' => []]);

        $this->assertSame('', trim($this->render($store)));
    }
}
