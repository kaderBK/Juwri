<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * BIOLINK-RUN cross-lane contract: the builder write methods Lane B's
 * BioLinkController calls on BioLinkService. Each must persist; tenant scoping is
 * enforced; createBlock migrates legacy links first (G4); editorBlocks keeps
 * `_i18n`; presets() returns the catalog.
 */
class BioLinkWriteTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    public function test_save_settings_persists(): void
    {
        $store = Store::factory()->create();

        $this->svc()->saveSettings($store->id, [
            'enabled' => true, 'preset' => 'majlis_gold', 'mode' => 'bio_first',
            'sections' => ['hours' => false], 'branch_pages' => true,
        ]);

        $s = $this->svc()->resolveSettings($store->id);
        $this->assertTrue($s['enabled']);
        $this->assertSame('majlis_gold', $s['preset']);
        $this->assertSame('bio_first', $s['mode']);
        $this->assertFalse($s['sections']['hours']);
        $this->assertTrue($s['branch_pages']);
    }

    public function test_create_block_persists_and_returns_editor_row(): void
    {
        $store = Store::factory()->create();

        $row = $this->svc()->createBlock($store->id, null, [
            'type' => 'link', 'label' => 'Order', 'url' => 'https://x.example',
            'config' => ['note' => 'hi'], 'active' => true,
        ]);

        $this->assertArrayHasKey('id', $row);
        $this->assertSame('Order', $row['label']);
        $this->assertSame(1, BioBlock::where('store_id', $store->id)->count());
    }

    public function test_create_block_migrates_legacy_first_g4(): void
    {
        $store = Store::factory()->create();
        $store->forceFill(['link1' => 'https://legacy.example', 'link_label1' => 'Legacy'])->save();

        $this->svc()->createBlock($store->id, null, ['type' => 'link', 'label' => 'New', 'url' => 'https://new.example']);

        // the legacy link was materialised BEFORE the new block, so both exist
        $labels = BioBlock::where('store_id', $store->id)->orderBy('position')->pluck('label')->all();
        $this->assertContains('Legacy', $labels);
        $this->assertContains('New', $labels);
        $this->assertTrue($this->svc()->isMigrated($store->id));
    }

    public function test_update_toggle_delete_reorder_persist_and_are_tenant_scoped(): void
    {
        $store = Store::factory()->create();
        $b1 = BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'One', 'position' => 0, 'active' => true]);
        $b2 = BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Two', 'position' => 1]);

        $this->svc()->updateBlock($store->id, $b1->id, ['label' => 'One Edited', 'url' => 'https://e.example']);
        $this->assertSame('One Edited', $b1->fresh()->label);

        $this->svc()->toggleBlock($store->id, $b1->id);
        $this->assertFalse((bool) $b1->fresh()->active);

        $this->svc()->reorderBlocks($store->id, [$b2->id, $b1->id]);
        $this->assertSame(0, (int) $b2->fresh()->position);
        $this->assertSame(1, (int) $b1->fresh()->position);

        $this->svc()->deleteBlock($store->id, $b2->id);
        $this->assertNull(BioBlock::find($b2->id));

        // tenant scoping: a foreign store cannot touch this block
        $foreign = Store::factory()->create();
        $this->svc()->updateBlock($foreign->id, $b1->id, ['label' => 'HACKED']);
        $this->assertSame('One Edited', $b1->fresh()->label);
        $this->svc()->deleteBlock($foreign->id, $b1->id);
        $this->assertNotNull(BioBlock::find($b1->id));
    }

    public function test_editor_blocks_keep_i18n_marker(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id,
            'config' => ['text' => 'Base', '_i18n' => ['ar' => ['text' => 'عربى']]],
        ]);

        $rows = $this->svc()->editorBlocks($store->id, null);
        $this->assertArrayHasKey('_i18n', $rows[0]['config']); // builder needs it intact
    }

    public function test_presets_returns_the_catalog_with_tiers(): void
    {
        $presets = $this->svc()->presets();

        $this->assertCount(38, $presets);
        $bySlug = collect($presets)->keyBy('slug');
        $this->assertSame('basic', $bySlug['classic_flat']['tier']);
        $this->assertSame('pro', $bySlug['neon_arcade']['tier']);
        $this->assertSame('menu_card', $bySlug['menu_card']['family']);
    }
}
