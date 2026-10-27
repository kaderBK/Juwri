<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\CompareDefs;
use Juwri\Modules\Content\Services\GuideDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO-growth: the tools, comparison pages and guides are SEO landing pages not
 * in the top nav — they MUST be reachable (users + crawlers) via footer links,
 * or they are orphans. Guards against silently dropping those columns.
 */
class FooterSeoLinksTest extends TestCase
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
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
    }

    public function test_footer_links_the_free_tools(): void
    {
        // features renders the shared landing footer (named route — reliable in-harness)
        $html = $this->get(route('features'))->assertOk()->getContent();
        $this->assertStringContainsString(route('tools.commission-calculator'), $html);
        $this->assertStringContainsString(route('tools.qr-generator'), $html);
        $this->assertStringContainsString(route('tools.menu-scanner'), $html);
    }

    public function test_footer_links_every_compare_and_guide_page(): void
    {
        $html = $this->get(route('features'))->assertOk()->getContent();

        foreach (CompareDefs::slugs() as $slug) {
            $this->assertStringContainsString(route('compare.show', $slug), $html, "footer links compare/$slug");
        }
        foreach (GuideDefs::slugs() as $slug) {
            $this->assertStringContainsString(route('guides.show', $slug), $html, "footer links guides/$slug");
        }
    }

    public function test_region_labels_follow_the_ui_locale_not_the_page_language(): void
    {
        // ma/tn pages are FR-first, but the footer "Regions" label is navigational
        // and must read English on /en (was leaking "Maroc"/"Tunisie").
        $en = \App\Services\CountryDefs::menu('en');
        $this->assertContains('Morocco', $en);
        $this->assertContains('Tunisia', $en);
        $this->assertNotContains('Maroc', $en);
        $this->assertNotContains('Tunisie', $en);
    }

    // NOTE: the top-nav "Free tools" dropdown was removed (it crowded the navbar
    // and squeezed the logo). The free tools remain reachable via the footer —
    // guarded by test_footer_links_the_free_tools above.
}
