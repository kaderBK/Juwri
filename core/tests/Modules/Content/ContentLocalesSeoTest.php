<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use App\Services\SitemapService;
use Juwri\Modules\Content\Support\ContentLocales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO (GSC 2026-09): guides / compare / country pages are served only in the
 * languages they are written in. Other /{locale}/ prefixes used to render the
 * fallback copy with a self canonical — English indexed as /ur/…, Spanish as
 * /tr/… — splitting ranking signals across up to 11 duplicates.
 */
class ContentLocalesSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        foreach (['en' => 'English', 'ar' => 'Arabic', 'es' => 'Spanish', 'tr' => 'Turkish', 'ur' => 'Urdu'] as $code => $name) {
            Language::updateOrCreate(['code' => $code], ['name' => $name, 'direction' => in_array($code, ['ar', 'ur'], true) ? 'rtl' : 'ltr', 'active' => 1]);
        }

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function prefixMode(): void
    {
        config(['settings.include_language_code' => 1]);
        config(['laravellocalization.supportedLocales' => get_active_languages()]);
    }

    public function test_written_locales_come_from_content_blocks_only(): void
    {
        $this->assertSame(['ar', 'en'], ContentLocales::of(['slug' => 'x', 'country' => 'SA', 'ar' => ['title' => 'a'], 'en' => ['title' => 'e']], ['country']));
        $this->assertSame(['es'], ContentLocales::of(['es' => ['title' => 'x']]));
        // Flat defs (no locale blocks) are English; non-locale array keys are ignored.
        $this->assertSame(['en'], ContentLocales::of(['name' => 'GloriaFood', 'better_fit' => ['a', 'b']]));
        // Inactive platform languages never count.
        $this->assertSame(['en'], ContentLocales::of(['en' => ['title' => 'e'], 'zz' => ['title' => 'z']]));
    }

    public function test_serve_in_prefers_current_then_english_then_first(): void
    {
        $this->assertSame('ar', ContentLocales::serveIn(['ar', 'en'], 'ar'));
        $this->assertSame('en', ContentLocales::serveIn(['ar', 'en'], 'ur'));
        $this->assertSame('es', ContentLocales::serveIn(['es'], 'tr'));
    }

    public function test_guide_in_an_unwritten_language_redirects_permanently(): void
    {
        $this->prefixMode();

        // verifactu is written in Spanish only; the (English) request 301s to it.
        $res = $this->get(route('guides.show', 'verifactu'));

        $res->assertStatus(301);
        $this->assertStringContainsString('/es/guides/verifactu', (string) $res->headers->get('Location'));
    }

    public function test_compare_page_written_in_the_request_language_is_served(): void
    {
        $this->prefixMode();
        app()->setLocale('en');

        // finedine is written in tr + en → an English request is served, not redirected.
        $this->get(route('compare.show', 'finedine'))->assertOk();

        // gloriafood is a flat (English) def: served in English.
        $this->get(route('compare.show', 'gloriafood'))->assertOk();
    }

    public function test_written_language_gets_self_canonical_and_scoped_hreflang(): void
    {
        $this->prefixMode();

        $res = $this->get(route('guides.show', 'zatca-einvoicing'));

        $res->assertOk();
        $html = (string) $res->getContent();
        $this->assertMatchesRegularExpression('/hreflang="ar"/', $html);
        $this->assertMatchesRegularExpression('/hreflang="en"/', $html);
        $this->assertMatchesRegularExpression('/hreflang="x-default"/', $html);
        // Only the written languages are advertised — never a phantom Urdu/Spanish copy.
        $this->assertDoesNotMatchRegularExpression('/hreflang="(ur|es|tr)"/', $html);
        $this->assertStringContainsString('rel="canonical"', $html);
    }

    public function test_country_page_scopes_hreflang_to_its_languages(): void
    {
        $this->prefixMode();

        // pk is written in English only.
        $html = (string) $this->get(route('country.landing', 'pk'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/hreflang="en"/', $html);
        $this->assertDoesNotMatchRegularExpression('/hreflang="(ar|ur|es|tr)"/', $html);
    }

    public function test_sitemap_lists_only_written_languages(): void
    {
        $this->prefixMode();

        $locs = array_column(SitemapService::marketingEntries(), 'loc');
        $verifactu = array_values(array_filter($locs, fn ($l) => str_contains($l, 'guides/verifactu')));
        $this->assertNotEmpty($verifactu);
        foreach ($verifactu as $l) {
            $this->assertStringContainsString('/es/guides/verifactu', $l, 'only the Spanish copy of a Spanish-only guide is listed');
        }
    }

    public function test_session_mode_is_unchanged(): void
    {
        // include_language_code off: one URL per page → no redirect, no hreflang.
        $res = $this->get(route('guides.show', 'verifactu'));

        $res->assertOk();
        $res->assertDontSeeHtml('hreflang');
    }
}
