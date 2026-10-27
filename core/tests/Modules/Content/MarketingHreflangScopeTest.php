<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\CompareDefs;
use Juwri\Modules\Content\Services\GuideDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Single-canonical marketing pages (guides, compare) render byte-identical copy
 * under every /{locale}/ prefix and canonical to their UNPREFIXED URL. Emitting
 * the sitewide 11-locale seo_hreflang_tags() on them advertised phantom locale
 * alternates that Google Search Console flagged as "Duplicate, Google chose a
 * different canonical than user" (~1.3K URLs). These pages now pass
 * suppressHreflang=true so the landing layout emits NO hreflang; their unprefixed
 * canonical consolidates the variants on its own.
 */
class MarketingHreflangScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_guide_page_emits_canonical_but_no_hreflang(): void
    {
        $slug = GuideDefs::slugs()[0];
        $res = $this->get(route('guides.show', $slug));

        $res->assertOk();
        $res->assertSeeHtml('rel="canonical"');        // canonical stays (consolidation signal)
        $res->assertDontSeeHtml('hreflang');           // no phantom locale alternates
        $res->assertDontSeeHtml('rel="alternate"');
    }

    public function test_compare_page_emits_no_hreflang(): void
    {
        $slug = CompareDefs::slugs()[0];
        $res = $this->get(route('compare.show', $slug));

        $res->assertOk();
        $res->assertSeeHtml('rel="canonical"');
        $res->assertDontSeeHtml('hreflang');
    }
}
