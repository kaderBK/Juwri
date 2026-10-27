<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Core\Models\Language;
use App\Services\CountryDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO Growth W3.0 — country landing-page template + route + defs whitelist.
 *
 * Rendered HTTP-through-controller (blade-direct render() misses the controller
 * contract, e.g. the demo-store existence probe). CountryDefs::dir() is pointed
 * at a fixtures dir so the template is proven end-to-end without shipping a stub
 * country into the live defs directory — that stays empty until a cite-checked
 * country lands (W3.1 Syria).
 */
class CountryTemplateTest extends TestCase
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
        config(['seo.country_defs_path' => base_path('tests/Fixtures/country-defs')]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
    }

    public function test_country_page_renders_through_controller(): void
    {
        $response = $this->get(route('country.landing', 'xx'));

        $response->assertOk();
        // H1 (defs title) + section content from every block.
        $response->assertSee('QR Menu Exampleland — Fixture Country Page');
        $response->assertSee('Local payments');
        $response->assertSee('Example Pay');
        $response->assertSee('Tax compliance');
        $response->assertSee('Keep your margin');
        $response->assertSee('Example pricing');
        $response->assertSee('Is there a QR menu for Exampleland restaurants?');
        // Shared closing conversion band.
        $response->assertSee(___('Ready to go live today?'));

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html, 'Dead mt-4 utility on /for/{country}');
        $this->assertStringNotContainsString('mt-5', $html, 'Dead mt-5 utility on /for/{country}');
        $this->assertStringNotContainsString('visually-hidden', $html, 'No .visually-hidden utility in this theme');
    }

    public function test_country_page_emits_valid_structured_data(): void
    {
        $html = $this->get(route('country.landing', 'xx'))->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1], 'No JSON-LD emitted on /for/{country}');

        $types = [];
        foreach ($m[1] as $json) {
            $decoded = json_decode(trim($json), true);
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), "Invalid JSON-LD block: {$json}");
            if (isset($decoded['@type'])) {
                $types[] = $decoded['@type'];
            }
        }
        $this->assertContains('BreadcrumbList', $types);
        $this->assertContains('FAQPage', $types);
    }

    public function test_canonical_points_at_the_country_url(): void
    {
        $html = $this->get(route('country.landing', 'xx'))->getContent();
        $this->assertStringContainsString('rel="canonical" href="'.route('country.landing', 'xx').'"', $html);
    }

    public function test_unknown_country_404s(): void
    {
        $this->get(route('country.landing', 'zz'))->assertNotFound();
    }

    public function test_defs_whitelist_and_traversal_guard(): void
    {
        $this->assertContains('xx', CountryDefs::slugs());
        $this->assertNull(CountryDefs::find('../../etc/passwd'));
        $this->assertNull(CountryDefs::find('UPPER'));
        // Footer Regions menu lists the fixture country with its localized name.
        $this->assertSame(['xx' => 'Exampleland'], CountryDefs::menu('en'));
    }

    public function test_localize_falls_back_to_default_then_first_block(): void
    {
        $def = CountryDefs::find('xx');
        // Arabic block exists → returned for ar.
        $this->assertSame('بلد المثال', CountryDefs::localize($def, 'ar')['name']);
        // Unknown locale → _default (en).
        $this->assertSame('Exampleland', CountryDefs::localize($def, 'de')['name']);
    }
}
