<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\CompareDefs;
use App\Services\CountryDefs;
use Juwri\Modules\Content\Services\GuideDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO-growth spacing guard.
 *
 * The comparison / guide / country / tool / landing pages were originally
 * authored with Bootstrap spacing utilities (pt-8, pt-12, pt-14, pt-lg-12/14/
 * 16/20/28, pb-lg-28/30) that this TRIMMED theme never generated — they resolve
 * to 0, so whole sections collapsed together with no vertical gap. The section
 * rhythm now lives in a dedicated `.ps-sec` class (product-sections.css). This
 * test fails if a page ever regresses to the dead utilities or drops `.ps-sec`.
 */
class SeoPageSpacingTest extends TestCase
{
    use RefreshDatabase;

    /** Section-padding utility combos this theme does NOT ship (resolve to 0). */
    private const DEAD_SECTION_CLASSES = [
        'ps-scope pt-8 pt-lg-12',
        'ps-scope pt-10 pt-lg-14',
        'ps-scope pt-12 pt-lg-16',
        'ps-scope pt-14 pt-lg-20',
        'ps-scope pb-15 pb-md-20 pb-lg-28',
    ];

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

    /** @return array<string,string> */
    private function pages(): array
    {
        $pages = [
            'features' => route('features'),
            'themes' => route('themes'),
            'whatsapp' => route('whatsapp.landing'),
            'no-commission' => route('no-commission'),
            'commission-calculator' => route('tools.commission-calculator'),
            'qr-generator' => route('tools.qr-generator'),
            'menu-scanner' => route('tools.menu-scanner'),
        ];
        if ($cmp = CompareDefs::slugs()[0] ?? null) {
            $pages["compare/$cmp"] = route('compare.show', $cmp);
        }
        if ($g = GuideDefs::slugs()[0] ?? null) {
            $pages["guide/$g"] = route('guides.show', $g);
        }
        if ($cc = CountryDefs::slugs()[0] ?? null) {
            $pages["country/$cc"] = route('country.landing', $cc);
        }

        return $pages;
    }

    public function test_seo_pages_use_the_ps_sec_rhythm_class(): void
    {
        foreach ($this->pages() as $label => $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/\\b(mk-sec|ps-sec)\\b/', $html, "$label should use a section rhythm class (.mk-sec on the kit)");
        }
    }

    public function test_seo_pages_do_not_reintroduce_dead_section_utilities(): void
    {
        foreach ($this->pages() as $label => $url) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach (self::DEAD_SECTION_CLASSES as $dead) {
                $this->assertStringNotContainsString(
                    $dead,
                    $html,
                    "$label reintroduced a section padding combo this theme does not ship: \"$dead\""
                );
            }
        }
    }
}
