<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Media;

use Juwri\Modules\Media\Services\UploadGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Abuse Guard P5 (5.3): pure upload-hardening helper predicates.
 */
class UploadGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults(): void
    {
        $this->assertSame(8, UploadGuard::maxMb());
        $this->assertSame(25000000, UploadGuard::maxPixels());
        $this->assertSame(30, UploadGuard::maxScanPages());
    }

    public function test_size_ok_boundary(): void
    {
        $cap = 8 * 1024 * 1024;

        $this->assertTrue(UploadGuard::sizeOk($cap));
        $this->assertTrue(UploadGuard::sizeOk($cap - 1));
        $this->assertFalse(UploadGuard::sizeOk($cap + 1));
    }

    public function test_pixels_ok_boundary(): void
    {
        $this->assertTrue(UploadGuard::pixelsOk(5000, 5000));   // 25,000,000
        $this->assertFalse(UploadGuard::pixelsOk(5001, 5000));  // over
    }

    public function test_mime_allowed(): void
    {
        $allowed = ['image/png', 'image/jpeg'];

        $this->assertTrue(UploadGuard::mimeAllowed('image/png', $allowed));
        $this->assertFalse(UploadGuard::mimeAllowed('image/gif', $allowed));
    }

    public function test_pages_ok_boundary(): void
    {
        $this->assertTrue(UploadGuard::pagesOk(30));
        $this->assertFalse(UploadGuard::pagesOk(31));
    }

    public function test_max_mb_reflects_setting(): void
    {
        config(['settings.abuse_upload_max_mb' => 1]);

        $this->assertSame(1, UploadGuard::maxMb());
        $this->assertTrue(UploadGuard::sizeOk(1024 * 1024));
        $this->assertFalse(UploadGuard::sizeOk(1024 * 1024 + 1));
    }

    public function test_image_within_pixels_fails_open_on_missing(): void
    {
        $this->assertTrue(UploadGuard::imageWithinPixels('/no/such/file.png'));
        $this->assertTrue(UploadGuard::imageWithinPixels(''));
    }

    public function test_allow_rejects_bad_mime_in_enforce(): void
    {
        config(['settings.abuse_upload_mode' => 'enforce']);
        $file = \Illuminate\Http\UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload');

        $this->assertFalse(UploadGuard::allow($file, 1));
        $this->assertSame(1, \Juwri\Core\Models\AbuseEvent::where('guard', 'upload')->count());
    }

    public function test_allow_warns_but_accepts_oversized(): void
    {
        config(['settings.abuse_upload_mode' => 'warn', 'settings.abuse_upload_max_mb' => 1]);
        $file = \Illuminate\Http\UploadedFile::fake()->create('big.jpg', 2048, 'image/jpeg'); // 2MB > 1MB

        $this->assertTrue(UploadGuard::allow($file, 1));
        $this->assertGreaterThanOrEqual(1, \Juwri\Core\Models\AbuseEvent::where('guard', 'upload')->count());
    }

    public function test_allow_off_accepts_everything(): void
    {
        config(['settings.abuse_upload_mode' => 'off']);
        $file = \Illuminate\Http\UploadedFile::fake()->create('evil.exe', 99999, 'application/x-msdownload');

        $this->assertTrue(UploadGuard::allow($file, 1));
    }
}
