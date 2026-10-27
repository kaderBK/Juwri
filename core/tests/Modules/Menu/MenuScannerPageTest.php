<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Free public tool: /tools/menu-scanner (SEO run W6.2).
 *
 * TEASER ONLY — the page must never post a file or call AI pre-auth. This
 * asserts the teaser markers (canned before→after sample + CTAs), the real
 * "after" capture, no upload endpoint, and the localized string contract.
 */
class MenuScannerPageTest extends TestCase
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

    public function test_page_renders_with_teaser_markers(): void
    {
        $response = $this->get(route('tools.menu-scanner'));

        $response->assertOk();
        $response->assertSee(___('AI Menu Scanner'));
        $response->assertSeeHtml('data-ms-root');
        $response->assertSeeHtml('data-ms-sample');
        $response->assertSeeHtml('menu-scanner.js');
        // Canned before -> after: paper mock + a kit mock of the digital menu
        // (no bought-theme screenshot).
        $response->assertSeeHtml('mk-ms__paper');
        $response->assertSeeHtml('mk-ms__digital');
        $response->assertSee(___('Your paper menu'));
        $response->assertSee(___('Your digital menu'));
        // The teaser boundary must be stated in the copy.
        $response->assertSee(___('Preview only — create your free account to scan your own menu.'));

        $html = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase('noindex', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
    }

    public function test_teaser_has_no_upload_form_or_file_input(): void
    {
        // Teaser only: nothing is uploaded server-side — no <form>, no file input.
        $html = $this->get(route('tools.menu-scanner'))->getContent();
        // Isolate the tool block so the site-wide footer/newsletter forms don't
        // trip the assertion.
        $start = strpos($html, 'data-ms-root');
        $end = strpos($html, 'menu-scanner.js');
        $block = substr($html, (int) $start, (int) $end - (int) $start);
        $this->assertStringNotContainsString('<form', $block, 'Teaser must not post anything');
        $this->assertStringNotContainsString('type="file"', $block, 'Teaser must not accept a real upload');
    }

    public function test_title_and_cta_present(): void
    {
        $html = $this->get(route('tools.menu-scanner'))->getContent();
        $this->assertStringContainsString('<title>AI Menu Scanner — Turn a Photo of Your Menu into a Digital One | ', $html);
        // CTA into the real product.
        $this->assertStringContainsString(route('register'), $html);
    }

    public function test_ar_renders_rtl(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('ar');

        $html = $this->get(route('tools.menu-scanner'))->getContent();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('ماسح المنيو بالذكاء الاصطناعي', $html);
    }

    public function test_localized_strings_seeded_for_all_locales(): void
    {
        foreach (['en', 'ar', 'de', 'es', 'fr', 'pt', 'ru', 'tr', 'zh', 'hi', 'ur'] as $locale) {
            $lang = require base_path("lang/$locale/lang.php");
            $this->assertArrayHasKey('drop_a_photo_of_your_menu', $lang, "[$locale] missing scanner key");
            if ($locale !== 'en') {
                $this->assertNotSame('Drop a photo of your menu', $lang['drop_a_photo_of_your_menu'], "[$locale] left untranslated");
            }
        }
    }
}
