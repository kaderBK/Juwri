<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Media\Services\StorageService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

class ImageOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_uploaded_images_are_converted_to_webp()
    {
        // Fake local disk using the public path mock if needed, but since StorageService writes to public_path(),
        // we can check if it saves it correctly. Wait, StorageService writes to public_path($path), so we can mock or check local files.
        // Let's create a temporary directory for tests under public_path.
        $testPath = 'storage/test_menu/';
        $fullTestPath = public_path($testPath);
        if (! file_exists($fullTestPath)) {
            @mkdir($fullTestPath, 0777, true);
        }

        $file = UploadedFile::fake()->image('item.jpg', 600, 600);

        $savedName = StorageService::upload($file, $testPath);

        $this->assertStringEndsWith('.webp', $savedName);
        $this->assertFileExists(public_path($testPath.$savedName));

        // Clean up
        @unlink(public_path($testPath.$savedName));
        @rmdir($fullTestPath);
    }

    public function test_p6234_exif_orientation_is_applied_before_webp_encode()
    {
        // 200 wide x 100 tall JPEG tagged Orientation=6 (rotate 90 CW) => must come out 100 x 200.
        $im = imagecreatetruecolor(200, 100);
        ob_start();
        imagejpeg($im, null, 90);
        $jpeg = (string) ob_get_clean();
        $exif = "Exif\0\0"."MM\0*\0\0\0\x08"."\0\1"."\x01\x12\0\3\0\0\0\1\0\6\0\0"."\0\0\0\0";
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        $tagged = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
        file_put_contents($tmp, $tagged);
        $file = new UploadedFile($tmp, 'phone.jpg', 'image/jpeg', null, true);

        $testPath = 'storage/test_exif/';
        @mkdir(public_path($testPath), 0777, true);
        $saved = StorageService::upload($file, $testPath);

        $this->assertStringEndsWith('.webp', $saved);
        [$w, $h] = getimagesize(public_path($testPath.$saved));
        $this->assertSame([100, 200], [$w, $h]);

        @unlink(public_path($testPath.$saved));
        @rmdir(public_path($testPath));
        @unlink($tmp);
    }

    public function test_convert_to_webp_command_backfills_legacy_images()
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'title' => 'Test Restaurant',
            'main_image' => 'logo.jpg',
            'cover_image' => 'cover.png',
        ]);

        $menu = Menu::create([
            'store_id' => $store->id,
            'name' => 'Pizza',
            'price' => 10.00,
            'image' => 'pizza.jpeg',
            'gallery_images' => ['gallery1.png', 'gallery2.jpg'],
        ]);

        // Place mock files
        $logoPath = public_path('storage/store/logo/');
        $coverPath = public_path('storage/store/cover/');
        $menuPath = public_path('storage/menu/');

        @mkdir($logoPath, 0777, true);
        @mkdir($coverPath, 0777, true);
        @mkdir($menuPath, 0777, true);

        // Save some dummy images
        $dummyImage = UploadedFile::fake()->image('temp.jpg', 100, 100);
        $dummyImage->move($logoPath, 'logo.jpg');

        $dummyImage2 = UploadedFile::fake()->image('temp.png', 100, 100);
        $dummyImage2->move($coverPath, 'cover.png');

        $dummyImage3 = UploadedFile::fake()->image('temp.jpeg', 100, 100);
        $dummyImage3->move($menuPath, 'pizza.jpeg');

        $dummyImage4 = UploadedFile::fake()->image('temp.png', 100, 100);
        $dummyImage4->move($menuPath, 'gallery1.png');

        $dummyImage5 = UploadedFile::fake()->image('temp.jpg', 100, 100);
        $dummyImage5->move($menuPath, 'gallery2.jpg');

        // Run the backfill command
        Artisan::call('storage:convert-to-webp');

        $store->refresh();
        $menu->refresh();

        $this->assertStringEndsWith('.webp', $store->main_image);
        $this->assertStringEndsWith('.webp', $store->cover_image);
        $this->assertStringEndsWith('.webp', $menu->image);

        $this->assertFileExists($logoPath.$store->main_image);
        $this->assertFileExists($coverPath.$store->cover_image);
        $this->assertFileExists($menuPath.$menu->image);

        $this->assertStringEndsWith('.webp', $menu->gallery_images[0]);
        $this->assertStringEndsWith('.webp', $menu->gallery_images[1]);
        $this->assertFileExists($menuPath.$menu->gallery_images[0]);
        $this->assertFileExists($menuPath.$menu->gallery_images[1]);

        // Clean up
        @unlink($logoPath.$store->main_image);
        @unlink($coverPath.$store->cover_image);
        @unlink($menuPath.$menu->image);
        @unlink($menuPath.$menu->gallery_images[0]);
        @unlink($menuPath.$menu->gallery_images[1]);
    }
}
