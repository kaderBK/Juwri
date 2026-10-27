<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * bio-t15 Lane A / A11: the `tipjar` block (SPEC §2 A11) is PRO (`bio_pro`
 * entitlement, SPEC §8) — gated server-side in TWO places, not just a UI
 * lock: (1) BioLinkService::blocksFor() never renders a tipjar row on the
 * public page for a non-entitled store, even if one somehow exists in the
 * DB; (2) BioLinkController::storeBlock()/updateBlock() reject writing a
 * tipjar block for a non-entitled owner.
 */
class BioLinkTipjarBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // The FeatureRegistry table-cache is a static memo that can be populated
        // mid-migration (before this run's feature rows are inserted), leaving the
        // dark `bio_pro` key missing from keyed() and isEnabled() fail-opening it to
        // true. Prod boots fresh so this never happens there; clear it here so the
        // storeAllows('bio_pro') gate reads the fully-migrated table.
        FeatureRegistry::clearCache();
    }

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    public function test_a_non_entitled_stores_public_render_never_shows_an_existing_tipjar_row(): void
    {
        [, $store] = $this->store();
        // The row exists in the DB (e.g. created while entitled, then downgraded) —
        // the public render must still never surface it.
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'tipjar', 'active' => true,
            'config' => ['mode' => 'url', 'url' => 'https://tips.example/x'],
        ]);

        $rows = $this->svc()->blocksFor($store->id);
        $this->assertEmpty(array_filter($rows, fn ($r) => $r['type'] === 'tipjar'));
    }

    public function test_an_entitled_stores_public_render_shows_the_tipjar_row(): void
    {
        [$owner, $store] = $this->store();
        config(['settings.admin_allow_bio_pro' => true]); // bio_pro = global kill-switch (matches the appearance PRO gate)

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'tipjar', 'active' => true,
            'config' => ['mode' => 'url', 'url' => 'https://tips.example/x'],
        ]);

        $rows = $this->svc()->blocksFor($store->id);
        $this->assertCount(1, array_filter($rows, fn ($r) => $r['type'] === 'tipjar'));
    }

    public function test_builder_editor_read_still_shows_the_row_regardless_of_entitlement(): void
    {
        [, $store] = $this->store();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'tipjar', 'active' => true,
            'config' => ['mode' => 'url', 'url' => 'https://tips.example/x'],
        ]);

        // editorBlocks() is the builder's own read path (Lane C shows a lock
        // badge there, C7) — it must not silently drop the row.
        $rows = $this->svc()->editorBlocks($store->id, null);
        $this->assertCount(1, array_filter($rows, fn ($r) => $r['type'] === 'tipjar'));
    }

    public function test_store_block_rejects_tipjar_for_a_non_entitled_owner_through_the_real_controller(): void
    {
        [$owner, $store] = $this->store();
        config(['settings.admin_allow_biolink' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $resp = $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'tipjar',
            'config' => ['mode' => 'url', 'url' => 'https://tips.example/x'],
        ]);

        $resp->assertStatus(422);
        $this->assertSame(0, BioBlock::where('store_id', $store->id)->where('type', 'tipjar')->count());
    }

    public function test_store_block_accepts_tipjar_for_an_entitled_owner_through_the_real_controller(): void
    {
        [$owner, $store] = $this->store();
        config(['settings.admin_allow_bio_pro' => true]); // bio_pro = global kill-switch (matches the appearance PRO gate)
        config(['settings.admin_allow_biolink' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $resp = $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'tipjar',
            'config' => ['mode' => 'url', 'url' => 'https://tips.example/x'],
        ]);

        $resp->assertOk();
        $this->assertSame(1, BioBlock::where('store_id', $store->id)->where('type', 'tipjar')->count());
    }

    public function test_update_block_rejects_flipping_an_existing_block_to_tipjar_for_a_non_entitled_owner(): void
    {
        [$owner, $store] = $this->store();
        $block = BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'link']);
        config(['settings.admin_allow_biolink' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $resp = $this->actingAs($owner)->postJson(route('biolink.blocks.update', $block->id), [
            'type' => 'tipjar',
            'config' => ['mode' => 'url', 'url' => 'https://tips.example/x'],
        ]);

        $resp->assertStatus(422);
        $this->assertSame('link', $block->fresh()->type);
    }

    public function test_amounts_mode_renders_a_button_per_amount(): void
    {
        [, $store] = $this->store();
        $row = ['id' => 1, 'label' => null, 'url' => null, 'config' => [
            'mode' => 'amounts', 'url' => 'https://tips.example/x', 'amounts' => [5, 10, 20], 'currency' => '$',
        ]];

        $html = (string) view('bio._blocks.tipjar', ['block' => $row])->render();

        $this->assertSame(3, substr_count($html, 'bio-tipjar__amt'));
        $this->assertStringContainsString('$5', $html);
        $this->assertStringContainsString('$20', $html);
    }

    public function test_no_url_degrades_to_nothing(): void
    {
        $row = ['id' => 1, 'label' => null, 'url' => null, 'config' => []];
        $html = trim((string) view('bio._blocks.tipjar', ['block' => $row])->render());
        $this->assertSame('', $html);
    }
}
