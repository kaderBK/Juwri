<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * localized_asset(): per-locale image swap with graceful fallback. A translated
 * screenshot lights up by dropping `name-<lang>.ext` next to the original.
 */
class LocalizedAssetTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Language::firstOrCreate(['code' => 'en'], ['name' => 'EN', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'AR', 'direction' => 'rtl', 'active' => 1]);

        // Assets are served from the repo root (/assets → dirname(base_path()).'/assets').
        $this->base = '/assets/tests/li-'.uniqid();
        $this->dir = dirname(base_path()).$this->base;
        @mkdir($this->dir, 0777, true);
        file_put_contents($this->dir.'/pic.webp', 'x');
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->dir.'/*'));
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_falls_back_to_original_when_no_variant(): void
    {
        app()->setLocale('ar');
        $this->assertSame($this->base.'/pic.webp', localized_asset($this->base.'/pic.webp'));
    }

    public function test_swaps_to_locale_variant_when_present(): void
    {
        file_put_contents($this->dir.'/pic-ar.webp', 'x');

        app()->setLocale('ar');
        $this->assertSame($this->base.'/pic-ar.webp', localized_asset($this->base.'/pic.webp'));

        app()->setLocale('en'); // no -en variant → original
        $this->assertSame($this->base.'/pic.webp', localized_asset($this->base.'/pic.webp'));
    }

    public function test_ignores_non_rootrelative_or_extensionless_paths(): void
    {
        $this->assertSame('https://cdn.test/a.webp', localized_asset('https://cdn.test/a.webp'));
        $this->assertSame('/assets/no-ext', localized_asset('/assets/no-ext'));
        $this->assertSame('', localized_asset(''));
    }
}
