<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Media\Services\StorageService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * F11 — thumbnail generation on upload + the menu:backfill-thumbs command.
 */
class MenuThumbnailTest extends TestCase
{
    use RefreshDatabase;

    public function test_thumbnail_writes_a_small_webp_variant(): void
    {
        $thumbDir = public_path('storage/menu/thumb/');
        @mkdir($thumbDir, 0777, true);

        $file = UploadedFile::fake()->image('burger.jpg', 500, 500);

        $name = StorageService::thumbnail($file, 'storage/menu/thumb/', 'burger.webp', '200', null);

        $this->assertNotNull($name, 'thumbnail() should return a filename on success');
        $this->assertStringEndsWith('.webp', $name);
        $this->assertFileExists($thumbDir.$name);

        // The thumb must actually be no wider than the requested 200px.
        $info = getimagesize($thumbDir.$name);
        $this->assertNotFalse($info);
        $this->assertLessThanOrEqual(200, $info[0], 'thumb width should be capped at 200px');

        @unlink($thumbDir.$name);
    }

    public function test_passthrough_sources_do_not_get_a_thumb(): void
    {
        // An SVG is passthrough (vector) — thumbnail() must skip it, not throw.
        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>'
        );

        $result = StorageService::thumbnail($svg, 'storage/menu/thumb/', 'logo.webp', '200', null);

        $this->assertNull($result);
    }

    public function test_backfill_command_generates_thumbs_for_existing_items(): void
    {
        $user = User::factory()->create();
        $store = Store::create(['user_id' => $user->id, 'title' => 'Thumb Cafe']);
        $menu = Menu::create([
            'store_id' => $store->id,
            'name' => 'Salad',
            'price' => 8.00,
            'image' => 'salad.webp',
        ]);

        $menuDir = public_path('storage/menu/');
        $thumbDir = public_path('storage/menu/thumb/');
        @mkdir($menuDir, 0777, true);
        UploadedFile::fake()->image('salad.webp', 500, 500)->move($menuDir, 'salad.webp');

        // Ensure no thumb yet, then backfill.
        @unlink($thumbDir.'salad.webp');
        Artisan::call('menu:backfill-thumbs');

        $this->assertFileExists($thumbDir.'salad.webp');
        $info = getimagesize($thumbDir.'salad.webp');
        $this->assertLessThanOrEqual(200, $info[0]);

        @unlink($menuDir.'salad.webp');
        @unlink($thumbDir.'salad.webp');
    }
}
