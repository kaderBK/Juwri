<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W1.2 (SEO run): the /no-commission zero-commission money landing page.
 *
 * Rendered HTTP-through-controller; title/H1 asserted via the NAMED route
 * (home '/' does not surface @section('title') in the harness).
 */
class NoCommissionLandingPageTest extends TestCase
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
        $response = $this->get(route('no-commission'));

        $response->assertOk();
        // H1 + <title> carry the KEYWORDS §ZeroCommission value verbatim.
        $response->assertSee(___('Online Ordering with Zero Commission'));
        // Content sections.
        $response->assertSee(___('What delivery apps really take'));
        $response->assertSee(___('One flat price. Every order is yours.'));
        $response->assertSee(___('Your own ordering channel'));
        // Commission table DATA (KEYWORDS §Commissions) + the flat-price counter row.
        $response->assertSeeHtml('nc-table');
        $response->assertSee('Yemeksepeti');
        $response->assertSee('Talabat');
        $response->assertSee(___('Up to'));
        $response->assertDontSeeHtml('zc-phone.webp');
        // FAQ.
        $response->assertSee(___('How much commission do delivery apps charge?'));
        // Shared closing conversion band.
        $response->assertSee(___('Ready to go live today?'));

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html, 'Dead mt-4 utility on /no-commission');
        $this->assertStringNotContainsString('mt-5', $html, 'Dead mt-5 utility on /no-commission');
        $this->assertStringNotContainsString('visually-hidden', $html, 'No .visually-hidden utility in this theme');
    }

    public function test_faqpage_schema_has_all_five_questions(): void
    {
        $html = $this->get(route('no-commission'))->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        $faq = null;
        $breadcrumb = null;
        foreach ($m[1] as $json) {
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded, 'A JSON-LD block failed to parse');
            if (($decoded['@type'] ?? null) === 'FAQPage') {
                $faq = $decoded;
            }
            if (($decoded['@type'] ?? null) === 'BreadcrumbList') {
                $breadcrumb = $decoded;
            }
        }
        $this->assertNotNull($faq, 'No FAQPage JSON-LD on /no-commission');
        $this->assertCount(5, $faq['mainEntity']);
        $this->assertSame('Question', $faq['mainEntity'][0]['@type']);
        $this->assertNotNull($breadcrumb, 'No BreadcrumbList JSON-LD on /no-commission');
    }

    public function test_internal_links_from_home_hero_footer_and_pricing(): void
    {
        // Footer link + home-hero trust-line link both live on the home page.
        $home = $this->get('/')->getContent();
        $this->assertStringContainsString(route('no-commission'), $home, 'Expected /no-commission on the home page (hero + footer)');
        $this->assertStringContainsString(___('0% commission on every order'), $home);

        // Pricing page links to the zero-commission page.
        $pricing = $this->get(route('pricing'))->getContent();
        $this->assertStringContainsString(route('no-commission'), $pricing, 'Expected /pricing to link the zero-commission page');
    }
}
