<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * bio-t15 Lane A / A13: `config.pinned`/`config.highlight` (SPEC §2 A13) are
 * cross-cutting on ANY block type. `pinned:true` sorts a block to the top of
 * its flow (stable within the pinned group); `highlight` adds a token-driven
 * animation class on the `_blocks.blade.php` wrapper — no per-block-type
 * work needed. Sanitized generically in BioLinkController::cleanConfig().
 */
class BioLinkPinnedHighlightTest extends TestCase
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

    public function test_pinned_blocks_sort_to_the_top_stably(): void
    {
        $store = Store::factory()->create();
        $a = BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'A', 'position' => 0, 'config' => []]);
        $b = BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'B', 'position' => 1, 'config' => ['pinned' => true]]);
        $c = BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'C', 'position' => 2, 'config' => []]);
        $d = BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'D', 'position' => 3, 'config' => ['pinned' => true]]);

        $labels = array_column($this->svc()->blocksFor($store->id), 'label');

        // pinned (B, D) first — in their ORIGINAL relative order — then the rest
        // (A, C) also in their original relative order.
        $this->assertSame(['B', 'D', 'A', 'C'], $labels);
    }

    public function test_no_pinned_blocks_keeps_the_natural_position_order(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'A', 'position' => 0, 'config' => []]);
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'B', 'position' => 1, 'config' => []]);

        $labels = array_column($this->svc()->blocksFor($store->id), 'label');
        $this->assertSame(['A', 'B'], $labels);
    }

    public function test_wrapper_carries_the_pinned_and_highlight_classes(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'link', 'label' => 'X', 'url' => 'https://e.example',
            'config' => ['pinned' => true, 'highlight' => 'glow'],
        ]);

        $rows = $this->svc()->blocksFor($store->id);
        $html = (string) view('bio._blocks', ['blocks' => $rows])->render();

        $this->assertStringContainsString('bio-block--pinned', $html);
        $this->assertStringContainsString('bio-highlight-glow', $html);
    }

    public function test_wrapper_has_no_highlight_class_when_highlight_is_none_or_absent(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'link', 'label' => 'X', 'url' => 'https://e.example',
            'config' => ['highlight' => 'none'],
        ]);

        $rows = $this->svc()->blocksFor($store->id);
        $html = (string) view('bio._blocks', ['blocks' => $rows])->render();

        $this->assertStringNotContainsString('bio-highlight-', $html);
        $this->assertStringNotContainsString('bio-block--pinned', $html);
    }

    public function test_controller_sanitizer_defaults_an_invalid_highlight_to_none_and_coerces_pinned_to_bool(): void
    {
        // Exercise cleanConfig() through the real HTTP route (the private
        // sanitizer has no public seam of its own).
        [$owner, $store] = $this->storeWithOwner();
        config(['settings.admin_allow_biolink' => true]);
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        $resp = $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'link', 'label' => 'Special',
            'config' => ['pinned' => '1', 'highlight' => 'sparkle-explosion'],
        ]);

        $resp->assertOk();
        $saved = BioBlock::where('store_id', $store->id)->where('label', 'Special')->first();
        $this->assertTrue($saved->config['pinned']);
        $this->assertSame('none', $saved->config['highlight']);
    }

    /** @return array{0: \Juwri\Core\Models\User, 1: Store} */
    private function storeWithOwner(): array
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
}
