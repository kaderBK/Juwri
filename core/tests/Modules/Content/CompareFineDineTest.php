<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\CompareDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO Growth W4.3 — FineDine comparison page (TR-focused: tr primary + en mirror).
 */
class CompareFineDineTest extends TestCase
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
        Language::updateOrCreate(['code' => 'tr'], ['name' => 'Turkish', 'direction' => 'ltr', 'active' => 1]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
    }

    public function test_finedine_page_renders_in_english(): void
    {
        $this->assertContains('finedine', CompareDefs::slugs());

        $response = $this->get(route('compare.show', 'finedine'));

        $response->assertOk();
        $response->assertSee('FineDine Alternative (2026)');
        $response->assertSee('FineDine');
        $response->assertSee('Trial only'); // verified: no free plan, trial only

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('compare.show', 'finedine').'"', $html);
    }

    public function test_finedine_page_renders_turkish_for_tr_locale(): void
    {
        app()->setLocale('tr');

        $response = $this->get(route('compare.show', 'finedine'));

        $response->assertOk();
        $response->assertSee('FineDine Alternatifi (2026)');
        $response->assertSee('Sadece deneme');
        $response->assertDontSee('FineDine Alternative (2026)');
    }

    public function test_finedine_emits_valid_structured_data(): void
    {
        $html = $this->get(route('compare.show', 'finedine'))->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1]);
        $types = [];
        foreach ($m[1] as $json) {
            $decoded = json_decode(trim($json), true);
            $this->assertSame(JSON_ERROR_NONE, json_last_error());
            if (isset($decoded['@type'])) {
                $types[] = $decoded['@type'];
            }
        }
        $this->assertContains('BreadcrumbList', $types);
        $this->assertContains('FAQPage', $types);
    }
}
