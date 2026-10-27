<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\CompareDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO Growth W4.1 — GloriaFood comparison page (the first real competitor).
 *
 * Hits the live defs (no fixture override): compare/gloriafood must render the
 * retirement hook, the comparison table, migration steps and FAQ schema.
 */
class CompareGloriaFoodTest extends TestCase
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

    public function test_gloriafood_compare_page_renders(): void
    {
        $this->assertContains('gloriafood', CompareDefs::slugs());

        $response = $this->get(route('compare.show', 'gloriafood'));

        $response->assertOk();
        $response->assertSee('GloriaFood Alternative (Free Migration Guide, 2026)');
        $response->assertSee('GloriaFood');
        // The retirement hook (the whole angle) and the migration section.
        $response->assertSee('30 April 2027');
        $response->assertSee('How to migrate off GloriaFood');
        $response->assertSee('30 Apr 2027'); // table cell

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('compare.show', 'gloriafood').'"', $html);
    }

    public function test_gloriafood_emits_valid_structured_data(): void
    {
        $html = $this->get(route('compare.show', 'gloriafood'))->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1]);
        $types = [];
        foreach ($m[1] as $json) {
            $decoded = json_decode(trim($json), true);
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), "Invalid JSON-LD: {$json}");
            if (isset($decoded['@type'])) {
                $types[] = $decoded['@type'];
            }
        }
        $this->assertContains('BreadcrumbList', $types);
        $this->assertContains('FAQPage', $types);
    }
}
