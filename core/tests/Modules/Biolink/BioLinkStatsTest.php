<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Models\BioClick;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * BIOLINK-RUN A11: statsFor() ranges + KPIs. Views come from the block_id=0
 * sentinel rows (G1), ctr = clicks/views, qr_scans = qr-source views — all from
 * bio_clicks, never store_page_views. Range chips window by day.
 */
class BioLinkStatsTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    private function viewRow(Store $store, string $day, string $source = 'direct', string $device = 'mobile', int $count = 1): void
    {
        BioClick::create(['store_id' => $store->id, 'block_id' => BioClick::VIEW_SENTINEL, 'day' => $day, 'source' => $source, 'device' => $device, 'count' => $count]);
    }

    private function click(Store $store, int $blockId, string $day, int $count = 1): void
    {
        BioClick::create(['store_id' => $store->id, 'block_id' => $blockId, 'day' => $day, 'source' => 'direct', 'device' => 'mobile', 'count' => $count]);
    }

    public function test_views_clicks_ctr_and_qr_scans(): void
    {
        $store = Store::factory()->create();
        $block = BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'link', 'label' => 'Order Delivery']);
        $today = now()->toDateString();

        $this->viewRow($store, $today, 'qr', 'mobile', 3);
        $this->viewRow($store, $today, 'social', 'desktop', 1);
        $this->click($store, $block->id, $today, 2);

        $stats = $this->svc()->statsFor($store->id, '30d');

        $this->assertSame(4, $stats['views']);
        $this->assertSame(2, $stats['clicks']);
        $this->assertSame(0.5, $stats['ctr']);       // 2 / 4
        $this->assertSame(3, $stats['qr_scans']);     // qr-source views
        $this->assertSame($block->id, $stats['per_block'][0]['block_id']);
        // Leaderboard names the block by its label/type, not a generic "Block".
        $this->assertSame('Order Delivery', $stats['per_block'][0]['label']);
        $this->assertSame('link', $stats['per_block'][0]['type']);
    }

    public function test_range_chips_window_by_day(): void
    {
        $store = Store::factory()->create();
        $this->viewRow($store, now()->toDateString());              // today
        $this->viewRow($store, now()->subDays(40)->toDateString()); // 40 days ago

        $this->assertSame(1, $this->svc()->statsFor($store->id, '7d')['views']);
        $this->assertSame(1, $this->svc()->statsFor($store->id, '30d')['views']);
        $this->assertSame(2, $this->svc()->statsFor($store->id, '90d')['views']);
        // unknown range → all-time
        $this->assertSame(2, $this->svc()->statsFor($store->id, 'all')['views']);
    }

    public function test_empty_store_reports_zeros(): void
    {
        $store = Store::factory()->create();

        $stats = $this->svc()->statsFor($store->id, '7d');

        $this->assertSame(0, $stats['views']);
        $this->assertSame(0, $stats['clicks']);
        $this->assertSame(0.0, $stats['ctr']);
        $this->assertSame(0, $stats['qr_scans']);
        $this->assertSame([], $stats['per_block']);
    }

    public function test_sources_and_devices_split_from_view_rows(): void
    {
        $store = Store::factory()->create();
        $today = now()->toDateString();
        $this->viewRow($store, $today, 'qr', 'mobile', 2);
        $this->viewRow($store, $today, 'social', 'desktop', 1);

        $stats = $this->svc()->statsFor($store->id, '30d');

        $sources = collect($stats['sources'])->pluck('count', 'source');
        $this->assertSame(2, $sources['qr']);
        $this->assertSame(1, $sources['social']);
        $devices = collect($stats['devices'])->pluck('count', 'device');
        $this->assertSame(2, $devices['mobile']);
        $this->assertSame(1, $devices['desktop']);
    }
}
