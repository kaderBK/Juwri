<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Models\BioClick;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * BIOLINK-RUN A1: proves the migrations, models and the LOCKED §3 BioLinkService
 * API skeleton work through real DB rows (sqlite). HTTP-level engine behaviour
 * (routing modes, public page, click endpoint) is proven in later A-task suites.
 */
class BioLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    public function test_resolve_settings_merges_defaults(): void
    {
        $store = Store::factory()->create();

        $settings = $this->svc()->resolveSettings($store->id);

        $this->assertFalse($settings['enabled']);
        $this->assertSame('classic_flat', $settings['preset']);
        $this->assertSame('menu_first', $settings['mode']);
        $this->assertIsArray($settings['sections']);
        $this->assertFalse($settings['branch_pages']);
    }

    public function test_resolve_settings_overlays_stored_partial(): void
    {
        $store = Store::factory()->create();
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode([
            'enabled' => true,
            'preset' => 'majlis_gold',
            'mode' => 'bio_first',
            'sections' => ['capture' => true],
        ]));
        StoreOption::flushOptionMemo();

        $settings = $this->svc()->resolveSettings($store->id);

        $this->assertTrue($settings['enabled']);
        $this->assertSame('majlis_gold', $settings['preset']);
        $this->assertSame('bio_first', $settings['mode']);
        // deep-merge keeps default sections while overriding capture
        $this->assertTrue($settings['sections']['capture']);
        $this->assertArrayHasKey('hours', $settings['sections']);
    }

    public function test_blocks_for_returns_active_ordered_rows_with_contract_shape(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Second', 'position' => 2]);
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'First', 'position' => 1]);
        BioBlock::factory()->inactive()->create(['store_id' => $store->id, 'label' => 'Hidden', 'position' => 3]);

        $blocks = $this->svc()->blocksFor($store->id);

        $this->assertCount(2, $blocks); // inactive excluded
        $this->assertSame('First', $blocks[0]['label']);
        $this->assertSame('Second', $blocks[1]['label']);
        foreach (['id', 'type', 'label', 'url', 'config', 'active', 'scheduled', 'clicks'] as $key) {
            $this->assertArrayHasKey($key, $blocks[0]);
        }
        $this->assertIsArray($blocks[0]['config']);

        // includeHidden surfaces the inactive block for the builder
        $this->assertCount(3, $this->svc()->blocksFor($store->id, null, true));
    }

    public function test_record_view_and_click_upsert_atomically_increment(): void
    {
        $store = Store::factory()->create();
        $block = BioBlock::factory()->create(['store_id' => $store->id]);

        $this->svc()->recordView($store->id, null, 'qr', 'mobile');
        $this->svc()->recordView($store->id, null, 'qr', 'mobile');
        $this->svc()->recordClick($store->id, $block->id, 'qr', 'mobile');

        // one PAGE-VIEW sentinel row, incremented to 2
        $view = BioClick::where('store_id', $store->id)->where('block_id', BioClick::VIEW_SENTINEL)->first();
        $this->assertNotNull($view);
        $this->assertSame(2, $view->count);

        // click row + clicks_cached bumped
        $click = BioClick::where('store_id', $store->id)->where('block_id', $block->id)->first();
        $this->assertSame(1, $click->count);
        $this->assertSame(1, (int) $block->fresh()->clicks_cached);
    }

    public function test_stats_for_reports_views_clicks_and_ctr(): void
    {
        $store = Store::factory()->create();
        $block = BioBlock::factory()->create(['store_id' => $store->id]);

        $this->svc()->recordView($store->id, null, 'direct', 'desktop');
        $this->svc()->recordView($store->id, null, 'social', 'mobile');
        $this->svc()->recordClick($store->id, $block->id, 'direct', 'desktop');

        $stats = $this->svc()->statsFor($store->id, '30d');

        $this->assertSame(2, $stats['views']);
        $this->assertSame(1, $stats['clicks']);
        $this->assertSame(0.5, $stats['ctr']);
        $this->assertSame($block->id, $stats['per_block'][0]['block_id']);
        $this->assertNotEmpty($stats['sources']);
        $this->assertNotEmpty($stats['devices']);
    }

    public function test_preset_allowed_basic_yes_pro_dark_no(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $store = Store::factory()->create();

        $this->assertTrue($this->svc()->presetAllowed($store->id, 'classic_flat'));
        $this->assertTrue($this->svc()->presetAllowed($store->id, 'majlis_gold'));
        // pro preset locked by default (ship dark / basic tier)
        $this->assertFalse($this->svc()->presetAllowed($store->id, 'neon_arcade'));
    }

    public function test_i18n_override_resolves_for_current_locale_and_strips_marker(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id,
            'type' => 'announcement',
            'label' => 'Open now',
            'config' => [
                'text' => 'We are open',
                '_i18n' => ['ar' => ['label' => 'مفتوح الآن', 'text' => 'نحن مفتوحون']],
            ],
        ]);

        app()->setLocale('en');
        $en = $this->svc()->blocksFor($store->id)[0];
        $this->assertSame('Open now', $en['label']);
        $this->assertSame('We are open', $en['config']['text']);
        $this->assertArrayNotHasKey('_i18n', $en['config']);

        app()->setLocale('ar');
        $ar = $this->svc()->blocksFor($store->id)[0];
        $this->assertSame('مفتوح الآن', $ar['label']);
        $this->assertSame('نحن مفتوحون', $ar['config']['text']);
        $this->assertArrayNotHasKey('_i18n', $ar['config']);
    }
}
