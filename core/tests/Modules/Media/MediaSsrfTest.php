<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Media;

use Juwri\Modules\Media\Services\MediaCopyException;
use Juwri\Modules\Media\Services\MediaCopyService;
use Juwri\Core\Services\SsrfGuard;
use Tests\TestCase;

/**
 * The media "copy from URL" / stock-image fetches take a caller-supplied URL and
 * must not let the server reach internal addresses (cloud metadata, loopback,
 * private ranges) — they now go through SsrfGuard like the webhook/import paths.
 */
class MediaSsrfTest extends TestCase
{
    public function test_ssrf_guard_blocks_internal_and_non_https(): void
    {
        // literal IPs → no DNS, deterministic.
        $this->assertFalse(SsrfGuard::isSafePublicUrl('http://8.8.8.8/'), 'http rejected (https-only)');
        $this->assertFalse(SsrfGuard::isSafePublicUrl('https://169.254.169.254/latest/meta-data/'), 'cloud metadata blocked');
        $this->assertFalse(SsrfGuard::isSafePublicUrl('https://127.0.0.1/'), 'loopback blocked');
        $this->assertFalse(SsrfGuard::isSafePublicUrl('https://10.1.2.3/'), 'private range blocked');
        $this->assertFalse(SsrfGuard::isSafePublicUrl('https://192.168.0.5/'), 'private range blocked');
        $this->assertTrue(SsrfGuard::isSafePublicUrl('https://8.8.8.8/'), 'public https IP allowed');
    }

    public function test_media_copy_from_url_refuses_an_internal_target(): void
    {
        app()->setLocale('en'); // deterministic message
        // Guard rejects before any HTTP fetch — no network, distinct "not allowed" message.
        $this->expectException(MediaCopyException::class);
        $this->expectExceptionMessage('not allowed');
        app(MediaCopyService::class)->fromUrl('https://169.254.169.254/latest/meta-data/', 1);
    }
}
