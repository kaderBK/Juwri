<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\CompareDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO Growth W4.0 — comparison-hub template + route + defs whitelist.
 *
 * Rendered HTTP-through-controller (blade-direct render() misses the controller
 * contract). CompareDefs::dir() is pointed at a test fixture dir so the template
 * is proven end-to-end without shipping a fabricated competitor into the live
 * defs directory — that stays empty until a cite-checked competitor lands
 * (W4.1 GloriaFood).
 */
class CompareTemplateTest extends TestCase
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

        // Whitelist source = fixtures, not the live theme partial dir.
        config(['seo.compare_defs_path' => base_path('tests/Fixtures/compare-defs')]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_compare_page_renders_through_controller(): void
    {
        $response = $this->get(route('compare.show', 'example-rival'));

        $response->assertOk();
        // H1 keyword (defs title) + both column headers.
        $response->assertSee('Example Rival Alternative for Restaurants');
        $response->assertSee('Example Rival');
        $response->assertSee(seo_brand());
        // Grouped table: a group label, a string cell, and the a11y tick label.
        $response->assertSee('Pricing');
        $response->assertSee('Up to 30%');
        $response->assertSee(___('Included'));
        $response->assertSee(___('Not included'));
        // Honest "better fit" section + verdict + migration notes.
        $response->assertSee('When Example Rival is the better fit');
        $response->assertSee('How to switch in three steps');
        // FAQ (also feeds FAQPage schema).
        $response->assertSee('Is juwri really commission-free?');

        // Regression guard: this theme ships no mt-4/mt-5 spacing utilities and
        // no .visually-hidden — all three are silent no-ops here.
        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html, 'Dead mt-4 utility on /compare');
        $this->assertStringNotContainsString('mt-5', $html, 'Dead mt-5 utility on /compare');
        $this->assertStringNotContainsString('visually-hidden', $html, 'Missing .visually-hidden utility used on /compare');
        $this->assertStringContainsString('cmp-table', $html);
    }

    public function test_compare_page_emits_valid_structured_data(): void
    {
        $html = $this->get(route('compare.show', 'example-rival'))->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1], 'No JSON-LD emitted on /compare');

        $types = [];
        foreach ($m[1] as $json) {
            $decoded = json_decode(trim($json), true);
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), "Invalid JSON-LD block: {$json}");
            if (isset($decoded['@type'])) {
                $types[] = $decoded['@type'];
            }
        }

        // Template contributes BreadcrumbList + FAQPage (defs supply faqs).
        $this->assertContains('BreadcrumbList', $types);
        $this->assertContains('FAQPage', $types);
    }

    public function test_canonical_points_at_the_compare_url(): void
    {
        $html = $this->get(route('compare.show', 'example-rival'))->getContent();

        $this->assertStringContainsString('rel="canonical" href="'.route('compare.show', 'example-rival').'"', $html);
    }

    public function test_unknown_competitor_slug_404s(): void
    {
        // Whitelist = defs files only; an un-authored competitor is never served.
        $this->get(route('compare.show', 'not-a-real-competitor'))->assertNotFound();
    }

    public function test_defs_whitelist_lists_fixture_slug(): void
    {
        $this->assertContains('example-rival', CompareDefs::slugs());
        $this->assertNull(CompareDefs::find('../../etc/passwd'));
        $this->assertNull(CompareDefs::find('UPPER'));
    }
}
