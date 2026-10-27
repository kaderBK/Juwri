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
 * BIOLINK-RUN A3 / G4: legacy link1..10 dual-read + cutover. Legacy links render
 * pre-migrate (read-only); migration creates real typed blocks and flips
 * bio_migrated; adding a block migrates first so the other links don't vanish
 * (no zero-rows cliff); the command is idempotent and honours --dry-run.
 */
class BioLinkLegacyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    private function postWithLegacyLinks(): Store
    {
        $store = Store::factory()->create();
        $store->forceFill([
            'link1' => 'https://one.example', 'link_label1' => 'One',
            'link2' => 'https://two.example', 'link_label2' => 'Two',
            'link3' => 'https://three.example', 'link_label3' => '', // label falls back to url
        ])->save();

        return $store;
    }

    public function test_legacy_links_render_pre_migrate_as_readonly_blocks(): void
    {
        $store = $this->postWithLegacyLinks();

        $blocks = $this->svc()->blocksFor($store->id);

        $this->assertCount(3, $blocks);
        $this->assertSame('link', $blocks[0]['type']);
        $this->assertSame('One', $blocks[0]['label']);
        $this->assertSame('https://one.example', $blocks[0]['url']);
        $this->assertTrue($blocks[0]['config']['legacy'] ?? false);
        // empty label falls back to the url
        $this->assertSame('https://three.example', $blocks[2]['label']);
        // nothing was written to bio_blocks by a mere read
        $this->assertSame(0, BioBlock::where('store_id', $store->id)->count());
    }

    public function test_migrate_creates_real_blocks_and_flips_flag(): void
    {
        $store = $this->postWithLegacyLinks();

        $created = $this->svc()->migrateLegacy($store->id);

        $this->assertSame(3, $created);
        $this->assertTrue($this->svc()->isMigrated($store->id));
        $this->assertSame(3, BioBlock::where('store_id', $store->id)->count());

        // now blocksFor returns REAL rows (no legacy marker), in position order
        $blocks = $this->svc()->blocksFor($store->id);
        $this->assertCount(3, $blocks);
        $this->assertArrayNotHasKey('legacy', $blocks[0]['config']);
        $this->assertSame('One', $blocks[0]['label']);

        // idempotent: a second migrate is a no-op
        $this->assertSame(0, $this->svc()->migrateLegacy($store->id));
        $this->assertSame(3, BioBlock::where('store_id', $store->id)->count());
    }

    public function test_no_zero_rows_cliff_when_adding_a_block(): void
    {
        // G4: the builder migrates legacy links FIRST, then adds the new block,
        // so the other links do not vanish the moment bio_blocks is non-empty.
        $store = $this->postWithLegacyLinks();

        $this->svc()->migrateLegacy($store->id);          // builder's pre-insert cutover
        BioBlock::factory()->create([                    // the newly added block
            'store_id' => $store->id, 'label' => 'Brand new', 'position' => 20,
        ]);

        $blocks = $this->svc()->blocksFor($store->id);

        $this->assertCount(4, $blocks); // 3 migrated legacy links + 1 new — none lost
        $labels = array_column($blocks, 'label');
        $this->assertContains('One', $labels);
        $this->assertContains('Two', $labels);
        $this->assertContains('Brand new', $labels);
    }

    public function test_command_dry_run_reports_without_writing_then_real_run_migrates(): void
    {
        $store = $this->postWithLegacyLinks();

        $this->artisan('bio:migrate-legacy', ['--dry-run' => true, '--post' => $store->id])
            ->assertExitCode(0);
        $this->assertSame(0, BioBlock::where('store_id', $store->id)->count());
        $this->assertFalse($this->svc()->isMigrated($store->id));

        $this->artisan('bio:migrate-legacy', ['--post' => $store->id])
            ->assertExitCode(0);
        $this->assertSame(3, BioBlock::where('store_id', $store->id)->count());
        $this->assertTrue($this->svc()->isMigrated($store->id));
    }
}
