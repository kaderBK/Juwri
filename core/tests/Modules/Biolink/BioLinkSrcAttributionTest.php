<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\StoreView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Analytics\Services\BehaviorAnalyticsService;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * BIOLINK-TPL T1-6 (A5): links from the bio page to the store's OWN storefront
 * carry src=bio at render, so the storefront's StoreView source split attributes the
 * traffic to the bio page. External/owner links, other stores and qr scans are never
 * touched; the analytics source split recognises the bio bucket.
 */
class BioLinkSrcAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    public function test_menu_block_storefront_url_gets_src_bio(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'menu', 'label' => 'Menu',
            'url' => route('publicView', $store->slug),
        ]);

        $row = $this->svc()->blocksFor($store->id)[0];

        $this->assertStringContainsString('src=bio', (string) $row['url']);
        $this->assertStringContainsString($store->slug, (string) $row['url']);
    }

    public function test_item_deep_link_gets_src_bio_appended_after_item(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'menu_item', 'label' => 'Special',
            'url' => route('publicView', $store->slug).'?item=42',
        ]);

        $url = (string) $this->svc()->blocksFor($store->id)[0]['url'];

        $this->assertStringContainsString('item=42', $url);
        $this->assertStringContainsString('&src=bio', $url); // added onto the existing query
    }

    public function test_owner_external_link_is_never_touched(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'link', 'label' => 'Instagram',
            'url' => 'https://instagram.com/mystore',
        ]);

        $this->assertSame('https://instagram.com/mystore', (string) $this->svc()->blocksFor($store->id)[0]['url']);
    }

    public function test_another_stores_storefront_is_not_attributed(): void
    {
        $store = Store::factory()->create();
        $other = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'link', 'label' => 'Sister store',
            'url' => route('publicView', $other->slug),
        ]);

        $this->assertStringNotContainsString('src=bio', (string) $this->svc()->blocksFor($store->id)[0]['url']);
    }

    public function test_qr_scan_url_is_left_alone(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'menu', 'label' => 'Menu',
            'url' => route('publicView', $store->slug).'?qr-id=abc',
        ]);

        $url = (string) $this->svc()->blocksFor($store->id)[0]['url'];
        $this->assertStringContainsString('qr-id=abc', $url);
        $this->assertStringNotContainsString('src=bio', $url);
    }

    public function test_existing_src_param_is_not_double_stamped(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'menu', 'label' => 'Menu',
            'url' => route('publicView', $store->slug).'?src=promo',
        ]);

        $url = (string) $this->svc()->blocksFor($store->id)[0]['url'];
        $this->assertStringContainsString('src=promo', $url);
        $this->assertStringNotContainsString('src=bio', $url);
    }

    public function test_analytics_source_split_recognises_the_bio_bucket(): void
    {
        $store = Store::factory()->create();
        // Storefront records source verbatim from ?src=; bio links stamp src=bio.
        StoreView::create(['store_id' => $store->id, 'date' => now(), 'source' => 'bio']);
        StoreView::create(['store_id' => $store->id, 'date' => now(), 'source' => 'bio']);
        StoreView::create(['store_id' => $store->id, 'date' => now(), 'source' => 'qr']);

        $split = BehaviorAnalyticsService::sourceSplit([$store->id]);

        $this->assertArrayHasKey('bio', $split);
        $this->assertSame(2, (int) $split['bio']);
        $this->assertSame(1, (int) $split['qr']);
    }
}
