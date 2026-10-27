<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W1.1 (SEO run): the /whatsapp-ordering money landing page.
 *
 * Rendered HTTP-through-controller (blade-direct render() misses the controller
 * contract); layout globals via shareLayoutGlobals(). Title/H1 assertions go
 * through the NAMED route — the home '/' route does not surface @section('title')
 * in the harness, but named-route pages do (HANDOFF test-harness quirk).
 */
class WhatsappLandingPageTest extends TestCase
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

    public function test_page_renders_through_controller_with_keyword_and_sections(): void
    {
        $response = $this->get(route('whatsapp.landing'));

        $response->assertOk();
        // H1 + <title> carry the KEYWORDS §WhatsApp value verbatim (exact-match).
        $response->assertSee(___('WhatsApp Ordering System for Restaurants'));
        // The four content sections.
        $response->assertSee(___('How WhatsApp ordering works'));
        $response->assertSee(___('Every order, as a tidy WhatsApp message'));
        $response->assertSee(___('Your channel, your margin'));
        $response->assertSee(___('Do you charge commission on WhatsApp orders?'));
        // Kit mock panel instead of the old capture; kit step list.
        $response->assertDontSeeHtml('features/2.webp');
        $response->assertSeeHtml('mk-steps');
        // Shared closing conversion band (marketing.sections.cta).
        $response->assertSee(___('Ready to go live today?'));

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html, 'Dead mt-4 utility on /whatsapp-ordering');
        $this->assertStringNotContainsString('mt-5', $html, 'Dead mt-5 utility on /whatsapp-ordering');
        $this->assertStringNotContainsString('visually-hidden', $html, 'No .visually-hidden utility in this theme');
    }

    public function test_faqpage_schema_has_all_five_questions(): void
    {
        $html = $this->get(route('whatsapp.landing'))->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        $faq = null;
        foreach ($m[1] as $json) {
            $decoded = json_decode($json, true);
            if (($decoded['@type'] ?? null) === 'FAQPage') {
                $faq = $decoded;
            }
        }
        $this->assertNotNull($faq, 'No FAQPage JSON-LD on /whatsapp-ordering');
        $this->assertCount(5, $faq['mainEntity']);
        $this->assertSame('Question', $faq['mainEntity'][0]['@type']);
    }

    public function test_breadcrumb_present(): void
    {
        $html = $this->get(route('whatsapp.landing'))->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        $breadcrumb = null;
        foreach ($m[1] as $json) {
            $decoded = json_decode($json, true);
            if (($decoded['@type'] ?? null) === 'BreadcrumbList') {
                $breadcrumb = $decoded;
            }
        }
        $this->assertNotNull($breadcrumb, 'No BreadcrumbList JSON-LD on /whatsapp-ordering');
        $this->assertSame(___('WhatsApp Orders'), $breadcrumb['itemListElement'][1]['name']);
    }

    public function test_internal_links_from_features_and_footer(): void
    {
        // Footer link (rendered on every page, incl. home).
        $home = $this->get('/')->getContent();
        $this->assertStringContainsString(route('whatsapp.landing'), $home, 'Expected /whatsapp-ordering in the footer');

        // /features platform-relevant WhatsApp row links to the landing page.
        $features = $this->get(route('features'))->getContent();
        $this->assertStringContainsString(route('whatsapp.landing'), $features, 'Expected /features to link the WhatsApp landing page');
        $this->assertStringContainsString(___('Explore WhatsApp ordering'), $features);
    }
}
