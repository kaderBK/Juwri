<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Media\Models\MediaAsset;
use Juwri\Modules\Media\Services\MediaReferences;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * storage/menu is a shared library: duplicating an item or cloning a store
 * copies the filename, not the file. Deleting any one row used to unlink the
 * file and leave the others rendering default.png forever.
 */
class SharedMenuImageTest extends TestCase
{
    use RefreshDatabase;

    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeFile(string $name): string
    {
        $path = public_path('storage/menu/'.$name);
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'x');
        $this->written[] = $path;

        return $path;
    }

    private function makeItem(Store $store, string $image): Menu
    {
        $category = MenuCategory::create([
            'store_id' => $store->id,
            'name' => 'Cat '.Str::random(4),
        ]);

        return Menu::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Item '.Str::random(4),
            'description' => 'T',
            'price' => 10,
            'image' => $image,
            'type' => 'veg',
        ]);
    }

    public function test_deleting_one_copy_keeps_the_shared_file(): void
    {
        $store = $this->makeRestaurant();
        $file = $this->makeFile('shared-'.Str::random(8).'.webp');
        $name = basename($file);

        $original = $this->makeItem($store, $name);
        $copy = $this->makeItem($store, $name);

        $original->forceDelete();

        $this->assertFileExists($file, 'the duplicate still points at this file');
        $this->assertSame($name, $copy->fresh()->image);
    }

    public function test_deleting_the_last_reference_removes_the_file(): void
    {
        $store = $this->makeRestaurant();
        $file = $this->makeFile('sole-'.Str::random(8).'.webp');

        $this->makeItem($store, basename($file))->forceDelete();

        $this->assertFileDoesNotExist($file);
    }

    public function test_a_media_library_asset_protects_the_file(): void
    {
        $store = $this->makeRestaurant();
        $file = $this->makeFile('lib-'.Str::random(8).'.webp');
        $name = basename($file);

        MediaAsset::create([
            'store_id' => $store->id,
            'filename' => $name,
            'original_name' => 'lib',
            'folder' => 'menu',
        ]);

        $this->makeItem($store, $name)->forceDelete();

        $this->assertFileExists($file, 'the library asset still names this file');
    }

    public function test_a_gallery_reference_protects_the_file(): void
    {
        $store = $this->makeRestaurant();
        $file = $this->makeFile('gal-'.Str::random(8).'.webp');
        $name = basename($file);

        $withGallery = $this->makeItem($store, 'default.png');
        $withGallery->gallery_images = [$name];
        $withGallery->save();

        $this->makeItem($store, $name)->forceDelete();

        $this->assertFileExists($file, 'another item uses this file in its gallery');
    }

    public function test_default_placeholder_is_never_removed(): void
    {
        // Not written/removed here: default.png is the real shared fallback
        // every theme falls back to, so the guard must treat it as referenced.
        $this->assertTrue(MediaReferences::isReferenced('default.png'));
        $this->assertTrue(MediaReferences::isReferenced('default.png', 5));
    }
}
