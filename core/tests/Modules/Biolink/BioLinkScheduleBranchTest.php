<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * BIOLINK-RUN A7: scheduled block visibility (store-tz windows) + branch pages
 * (`?branch=`) where a branch-scoped block overrides a store-wide block of the
 * same type.
 */
class BioLinkScheduleBranchTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    public function test_scheduled_windows_filter_public_blocks_but_builder_sees_all(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Future', 'position' => 1, 'visible_from' => now()->addDay()]);
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Expired', 'position' => 2, 'visible_until' => now()->subDay()]);
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Live', 'position' => 3, 'visible_from' => now()->subDay(), 'visible_until' => now()->addDay()]);
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Always', 'position' => 4]);

        $public = array_column($this->svc()->blocksFor($store->id), 'label');
        $this->assertSame(['Live', 'Always'], $public);

        // the builder (includeHidden) sees the scheduled/expired ones too, flagged
        $all = $this->svc()->blocksFor($store->id, null, true);
        $this->assertCount(4, $all);
        $future = collect($all)->firstWhere('label', 'Future');
        $this->assertTrue($future['scheduled']);
    }

    public function test_schedule_resolves_in_store_timezone(): void
    {
        $store = Store::factory()->create();
        StoreOption::updateStoreOption($store->id, 'timezone', 'Asia/Riyadh');
        StoreOption::flushOptionMemo();

        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Live', 'visible_from' => now()->subDay(), 'visible_until' => now()->addDay()]);

        $labels = array_column($this->svc()->blocksFor($store->id), 'label');
        $this->assertContains('Live', $labels); // store-tz window still live
    }

    public function test_branch_block_overrides_same_type_store_block(): void
    {
        $store = Store::factory()->create();
        $branch = Branch::factory()->create(['store_id' => $store->id]);

        BioBlock::factory()->type('hours')->create(['store_id' => $store->id, 'label' => 'Store Hours', 'position' => 1]);
        BioBlock::factory()->type('link')->create(['store_id' => $store->id, 'label' => 'Store Link', 'position' => 2]);
        BioBlock::factory()->type('hours')->create(['store_id' => $store->id, 'branch_id' => $branch->id, 'label' => 'Branch Hours', 'position' => 3]);

        // main page: store-wide only, no branch rows
        $main = array_column($this->svc()->blocksFor($store->id, null), 'label');
        $this->assertSame(['Store Hours', 'Store Link'], $main);

        // branch page: branch hours REPLACES store hours; store link stays
        $branchPage = array_column($this->svc()->blocksFor($store->id, $branch->id), 'label');
        $this->assertContains('Branch Hours', $branchPage);
        $this->assertContains('Store Link', $branchPage);
        $this->assertNotContains('Store Hours', $branchPage);
    }

    public function test_public_page_resolves_branch_query_param(): void
    {
        $store = Store::factory()->create();
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode(['enabled' => true]));
        StoreOption::flushOptionMemo();

        $branch = Branch::factory()->create(['store_id' => $store->id]);
        BioBlock::factory()->type('link')->create(['store_id' => $store->id, 'branch_id' => $branch->id, 'label' => 'BranchOnlyLink']);

        // valid child branch → branch block shows
        $this->get("/{$store->slug}/bio?branch={$branch->id}")->assertSee('BranchOnlyLink');

        // foreign / invalid branch id → main page (branch block absent)
        $foreign = Branch::factory()->create(); // belongs to another store
        $this->get("/{$store->slug}/bio?branch={$foreign->id}")->assertDontSee('BranchOnlyLink');
    }
}
