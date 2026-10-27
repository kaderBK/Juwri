<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use App\Services\CountryDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO Growth W3.1–W3.8 — the shipped country landing pages (live defs dir).
 *
 * Unlike CountryTemplateTest (which points the loader at a fixture), this test
 * renders every REAL country defs file through the controller, so a broken or
 * malformed country page fails here. Each page must return 200 with its
 * localized H1 and a FAQPage schema, and carry none of the theme's dead
 * spacing utilities.
 */
class CountryPagesTest extends TestCase
{
    use RefreshDatabase;

    /** The countries this run ships (W3.1 sy … W3.8 sg/my). */
    private const EXPECTED = ['ae', 'eg', 'jo', 'ma', 'my', 'pk', 'sa', 'sg', 'sy', 'tn'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
    }

    public function test_every_expected_country_is_published(): void
    {
        $slugs = CountryDefs::slugs();
        foreach (self::EXPECTED as $cc) {
            $this->assertContains($cc, $slugs, "Country defs missing for {$cc}");
        }
    }

    public function test_all_live_country_pages_render_with_h1_and_faq_schema(): void
    {
        foreach (CountryDefs::slugs() as $cc) {
            $def = CountryDefs::find($cc);
            $this->assertNotNull($def, "find() returned null for {$cc}");
            $c = CountryDefs::localize($def, 'en');

            $response = $this->get(route('country.landing', $cc));
            $response->assertOk();

            $html = $response->getContent();
            // The served block's H1 keyword is present.
            $this->assertStringContainsString('>'.e((string) $c['title']).'</h1>', $html, "Missing H1 for {$cc}");
            // FAQPage schema built from the defs' faqs.
            $this->assertStringContainsString('"@type":"FAQPage"', str_replace(' ', '', $html), "No FAQPage schema for {$cc}");
            // Dead utilities guard.
            $this->assertStringNotContainsString('mt-4', $html, "Dead mt-4 on /for/{$cc}");
            $this->assertStringNotContainsString('mt-5', $html, "Dead mt-5 on /for/{$cc}");
            $this->assertStringNotContainsString('visually-hidden', $html, "visually-hidden on /for/{$cc}");
        }
    }

    public function test_locale_variants_serve_the_right_block(): void
    {
        // Saudi ships both ar and en with distinct verbatim H1s (KEYWORDS §Countries).
        $sa = CountryDefs::find('sa');
        $this->assertStringContainsString('ZATCA & SFDA Ready', CountryDefs::localize($sa, 'en')['title']);
        $this->assertStringContainsString('زاتكا', CountryDefs::localize($sa, 'ar')['title']);

        // Morocco is FR-first with no en block → en request falls back to fr.
        $ma = CountryDefs::find('ma');
        $this->assertStringContainsString('Maroc', CountryDefs::localize($ma, 'en')['title']);
    }
}
