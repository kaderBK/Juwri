<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Juwri\Modules\Media\Services\StorageService;
use Tests\TestCase;

/**
 * P6-233: undecodable uploads are never stored raw under the client's
 * extension, and stored images carry no EXIF/GPS.
 */
class UploadDecodeFallbackTest extends TestCase
{
    use RefreshDatabase;

    private string $dir = 'storage/p6233_test/';

    protected function setUp(): void
    {
        parent::setUp();
        @mkdir(public_path($this->dir), 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob(public_path($this->dir).'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(public_path($this->dir));
        parent::tearDown();
    }

    /** A real JPEG with an APP1 Exif segment holding a GPS marker. */
    private function jpegWithGps(): string
    {
        $im = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($im);
        $jpg = (string) ob_get_clean();
        $payload = "Exif\0\0MM\0*\0\0\0\x08GPSLatitude=51.5007N GPSLongitude=0.1246W";
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpg, 0, 2).$app1.substr($jpg, 2);
    }

    private function upload(string $bytes, string $name): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'p6233');
        file_put_contents($tmp, $bytes);

        return StorageService::upload(new UploadedFile($tmp, $name, null, null, true), $this->dir);
    }

    public function test_truncated_jpeg_named_html_is_refused(): void
    {
        $bytes = substr($this->jpegWithGps(), 0, 120); // Exif kept, image data cut
        $name = $this->upload($bytes, 'evil.html');

        $this->assertSame('', $name);
        $this->assertSame([], glob(public_path($this->dir).'*.html') ?: []);
    }

    public function test_decodable_jpeg_is_reencoded_without_gps(): void
    {
        $name = $this->upload($this->jpegWithGps(), 'home.html');

        $this->assertStringEndsWith('.webp', $name);
        $stored = (string) file_get_contents(public_path($this->dir.$name));
        $this->assertStringNotContainsString('GPS', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
    }

    public function test_jpeg_renamed_gif_is_not_passed_through(): void
    {
        $name = $this->upload($this->jpegWithGps(), 'photo.gif');

        $this->assertStringEndsWith('.webp', $name);
        $this->assertStringNotContainsString('GPS', (string) file_get_contents(public_path($this->dir.$name)));
    }

    public function test_undecodable_bmp_falls_back_as_bmp(): void
    {
        // BMP magic + garbage: sniffed as BMP, decode fails, allow-listed fallback.
        $bytes = 'BM'.pack('V', 70).str_repeat("\0", 4).pack('V', 54).pack('V', 40).str_repeat("\x01", 40);
        $name = $this->upload($bytes, 'x.html');

        if ($name !== '') {
            $this->assertStringEndsWith('.bmp', $name);
        }
        $this->assertSame([], glob(public_path($this->dir).'*.html') ?: []);
    }
}
