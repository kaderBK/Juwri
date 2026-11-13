<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Models\StoreView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Models\BioClick;
use Tests\TestCase;

/**
 * BIOLINK-RUN A6: the public click beacon + bio:aggregate reconciliation. A tap
 * records a bio_clicks row (with the echoed source, G5) AND bumps clicks_cached
 * (G8); it never writes store_page_views (G1). Foreign/dark clicks no-op. The nightly
 * command repairs clicks_cached drift.
 */
class BioLinkClickTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class]);
    }

    private function enabledStore(): Store
    {
        $store = Store::factory()->create();
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode(['enabled' => true]));
        StoreOption::flushOptionMemo();

        return $store;
    }

    public function test_click_records_bio_click_and_clicks_cached_with_echoed_source(): void
    {
        $store = $this->enabledStore();
        $block = BioBlock::factory()->create(['store_id' => $store->id]);

        $res = $this->post("/{$store->slug}/bio/c/{$block->id}", ['src' => 'social']);

        $res->assertStatus(204);
        $click = BioClick::where('store_id', $store->id)->where('block_id', $block->id)->first();
        $this->assertNotNull($click);
        $this->assertSame(1, $click->count);
        $this->assertSame('social', $click->source); // G5: echoed source
        $this->assertSame(1, (int) $block->fresh()->clicks_cached); // G8
        // G1: no store_page_views written by a bio click
        $this->assertSame(0, StoreView::where('store_id', $store->id)->count());
    }

    public function test_click_on_foreign_block_is_a_noop(): void
    {
        $store = $this->enabledStore();
        $other = Store::factory()->create();
        $foreign = BioBlock::factory()->create(['store_id' => $other->id]);

        $this->post("/{$store->slug}/bio/c/{$foreign->id}", ['src' => 'direct'])->assertStatus(204);

        $this->assertSame(0, BioClick::where('store_id', $store->id)->count());
        $this->assertSame(0, (int) $foreign->fresh()->clicks_cached);
    }

    public function test_click_on_dark_store_is_a_noop(): void
    {
        $store = Store::factory()->create(); // feature dark (no config/grant)
        $block = BioBlock::factory()->create(['store_id' => $store->id]);

        $this->post("/{$store->slug}/bio/c/{$block->id}", ['src' => 'qr'])->assertStatus(204);

        $this->assertSame(0, BioClick::where('store_id', $store->id)->count());
    }

    public function test_unknown_slug_beacon_returns_204_not_404(): void
    {
        BioBlock::factory()->create();
        $this->post('/no-such-store/bio/c/1', ['src' => 'direct'])->assertStatus(204);
    }

    public function test_bio_aggregate_reconciles_clicks_cached_drift(): void
    {
        $store = Store::factory()->create();
        $block = BioBlock::factory()->create(['store_id' => $store->id, 'clicks_cached' => 99]);
        // two real click rows summing to 2, but clicks_cached says 99 (drift)
        BioClick::create(['store_id' => $store->id, 'block_id' => $block->id, 'day' => now()->toDateString(), 'source' => 'qr', 'device' => 'mobile', 'count' => 2]);

        $this->artisan('bio:aggregate', ['--post' => $store->id])->assertExitCode(0);

        $this->assertSame(2, (int) $block->fresh()->clicks_cached);
    }
}
