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
 * bio-t15 Lane A / A8: the `gallery` block (SPEC §2 A8) — a CSS scroll-snap
 * carousel or a 2-col grid, no JS library. Images resolve through media_url()
 * (or pass through an absolute URL untouched), lazy-load, and are capped at 12.
 */
class BioLinkGalleryBlockTest extends TestCase
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

        return (string) view('bio._blocks.gallery', ['block' => $row, 'store' => $store])->render();
    }

    public function test_carousel_layout_renders_scroll_snap_markup_with_captions(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'gallery',
            'config' => [
                'layout' => 'carousel', 'ratio' => '4:3',
                'images' => [['image' => 'a.jpg', 'caption' => 'Terrace seating'], 'https://cdn.example/b.jpg'],
            ],
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString('bio-gallery--carousel', $html);
        $this->assertStringContainsString('bio-gallery--r4-3', $html);
        $this->assertStringContainsString('Terrace seating', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('https://cdn.example/b.jpg', $html); // absolute URL passed through
    }

    public function test_grid_layout_class_applied(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'gallery',
            'config' => ['layout' => 'grid', 'images' => ['a.jpg', 'b.jpg']],
        ]);

        $this->assertStringContainsString('bio-gallery--grid', $this->render($store));
    }

    public function test_images_are_capped_at_twelve(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'gallery',
            'config' => ['images' => array_map(fn ($i) => "img{$i}.jpg", range(1, 20))],
        ]);

        $html = $this->render($store);
        $this->assertSame(12, substr_count($html, '<figure class="bio-gallery__item">'));
    }

    public function test_empty_or_blank_entries_are_skipped(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'gallery',
            'config' => ['images' => ['', '   ', ['image' => '']]],
        ]);

        $this->assertSame('', trim($this->render($store)));
    }

    public function test_no_images_degrades_to_nothing(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'gallery', 'config' => []]);

        $this->assertSame('', trim($this->render($store)));
    }
}
