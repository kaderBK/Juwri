<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\CompareDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO Growth W4.5 — Anota AI comparison page (PT-BR primary + en mirror).
 * "Alternativa" framing; never the "robô de whatsapp" head term.
 */
class CompareAnotaAiTest extends TestCase
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
        Language::updateOrCreate(['code' => 'pt'], ['name' => 'Portuguese', 'direction' => 'ltr', 'active' => 1]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
    }

    public function test_anota_ai_page_renders_in_english(): void
    {
        $this->assertContains('anota-ai', CompareDefs::slugs());

        $response = $this->get(route('compare.show', 'anota-ai'));

        $response->assertOk();
        $response->assertSee('Anota AI Alternative (2026)');
        $response->assertSee('Anota AI');
        $response->assertSee('From R$ 299.99/mo'); // verified: no free plan, paid from Start

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('compare.show', 'anota-ai').'"', $html);
    }

    public function test_anota_ai_page_renders_portuguese_for_pt_locale(): void
    {
        app()->setLocale('pt');

        $response = $this->get(route('compare.show', 'anota-ai'));

        $response->assertOk();
        $response->assertSee('Alternativa ao Anota AI (2026)');
        $response->assertSee('A partir de R$ 299,99/mês');
        $response->assertDontSee('Anota AI Alternative (2026)');
    }

    public function test_anota_ai_emits_valid_structured_data(): void
    {
        $html = $this->get(route('compare.show', 'anota-ai'))->getContent();

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
