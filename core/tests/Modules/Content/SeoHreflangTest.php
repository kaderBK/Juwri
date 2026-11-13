<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rev/seo R4. Prefix-mode routes cannot register under test boot (session-mode
 * default), so the helper is exercised directly with faked config; live route
 * output is curl-verified in BUILD-LOG R4.
 */
class SeoHreflangTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Language::query()->update(['active' => 0]); // migrations seed en+fr active
        foreach ([['ar', 'rtl'], ['en', 'ltr'], ['tr', 'ltr']] as [$code, $dir]) {
            Language::updateOrCreate(['code' => $code], ['name' => strtoupper($code), 'direction' => $dir, 'active' => 1]);
        }
        Language::updateOrCreate(['code' => 'de'], ['name' => 'DE', 'direction' => 'ltr', 'active' => 0]);
    }

    private function enablePrefixMode(): void
    {
        config(['settings.include_language_code' => 1]);
        config(['laravellocalization.supportedLocales' => get_active_languages()]);
    }

    public function test_session_mode_emits_nothing(): void
    {
        config(['settings.include_language_code' => 0]);

        $this->assertSame('', seo_hreflang_tags());
    }

    public function test_prefix_mode_emits_full_mutual_set_with_x_default(): void
    {
        $this->enablePrefixMode();

        $html = seo_hreflang_tags();

        foreach (['ar', 'en', 'tr', 'x-default'] as $code) {
            $this->assertStringContainsString('hreflang="'.$code.'"', $html);
        }
        $this->assertSame(4, substr_count($html, 'rel="alternate"'), 'active langs + x-default, nothing more');
        $this->assertStringNotContainsString('hreflang="de"', $html, 'inactive language must not appear');
    }

    public function test_default_locale_and_x_default_are_unprefixed(): void
    {
        $this->enablePrefixMode();

        $html = seo_hreflang_tags();

        // app.locale=ar (env DEFAULT_LANGUAGE) is hidden in URLs — /ar/ must never leak.
        $this->assertStringNotContainsString('/ar', $html);
        preg_match('/hreflang="x-default" href="([^"]+)"/', $html, $m);
        preg_match('/hreflang="ar" href="([^"]+)"/', $html, $m2);
        $this->assertSame($m[1], $m2[1], 'x-default must equal the default-locale URL');
    }

    public function test_no_insecure_urls(): void
    {
        $this->enablePrefixMode();

        $this->assertStringNotContainsString('http://', str_replace('https://', '', seo_hreflang_tags()));
    }
}
