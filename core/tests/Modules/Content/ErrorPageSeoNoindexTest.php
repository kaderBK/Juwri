<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Language;
use Tests\TestCase;

/**
 * Error pages (404/403/5xx, rendered through resources/views/errors/layout.blade.php)
 * must never look indexable to search engines.
 *
 * Regression: the owner error layout included the full generic head partial, which
 * emitted a self-referential <link rel="canonical"> plus the 11-locale
 * seo_hreflang_tags() set for the CURRENT (error) URL. A 404 body therefore
 * advertised eleven MORE crawlable locale siblings — /{locale}/{slug}?item=… — each
 * itself a 404 that advertised eleven more. Google Search Console's "Not found (404)"
 * and "Alternate page with proper canonical" buckets ballooned from this
 * self-amplification. Error pages now emit <meta name="robots" content="noindex,…">
 * and NO canonical/hreflang.
 */
class ErrorPageSeoNoindexTest extends TestCase
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

    public function test_unknown_slug_404_is_noindex_with_no_locale_alternates(): void
    {
        // No such store → StoreController@publicView aborts 404 → themed error view.
        $res = $this->get('/no-such-store-xyz123');

        $res->assertNotFound();
        // Must tell crawlers not to index the error page…
        $res->assertSeeHtml('name="robots"');
        $res->assertSeeHtml('noindex');
        // …and must NOT advertise locale alternates (each is another 404) or
        // self-canonicalise its own error URL.
        $res->assertDontSeeHtml('hreflang');
        $res->assertDontSeeHtml('rel="alternate"');
        $res->assertDontSeeHtml('rel="canonical"');
    }
}
