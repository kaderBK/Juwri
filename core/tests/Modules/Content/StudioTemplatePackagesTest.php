<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioTemplateService;
use Tests\TestCase;

/**
 * P1-4/P1-9: every REAL template package in resources/views/studio/templates must
 * render a standalone page from a rich sample draft — EN and AR/RTL — with no
 * external requests and user text escaped. New packages get covered automatically.
 */
class StudioTemplatePackagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['studio.templates_path' => null]); // the real dir
    }

    protected function makeSession(): StudioSession
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'), json_encode(['categories' => [
            ['name' => 'Starters', 'items' => [
                ['name' => 'Hummus & <script>alert(1)</script>', 'description' => 'Creamy chickpeas, warm pita', 'price' => 19.8,
                    'variants' => [['title' => 'Size', 'options' => ['Small', 'Large'], 'prices' => [19.8, 29]]],
                    'extras' => [['title' => 'Extra pita', 'price' => 3]],
                    'tags' => ['vegan', 'contains_nuts']],
                ['name' => 'Halloumi', 'description' => '', 'price' => 24, 'variants' => [], 'extras' => []],
            ]],
            ['name' => 'مشاوي', 'items' => [
                ['name' => 'شيش طاووق', 'description' => 'مع صلصة الثوم', 'price' => 42.5, 'variants' => [], 'extras' => []],
            ]],
        ]]));

        return $s;
    }

    public function test_every_real_package_renders_en_and_rtl_with_no_external_requests(): void
    {
        $svc = app(StudioTemplateService::class);
        $all = $svc->all();
        $this->assertNotEmpty($all, 'no template packages discovered in the real dir');

        foreach (array_keys($all) as $slug) {
            foreach ([false, true] as $rtl) {
                $html = $svc->render($this->makeSession(), $slug, [
                    'rtl' => $rtl,
                    'slots' => ['store_name' => 'Casa Test', 'tagline' => 'Since 1999', 'footer_note' => 'Prices include VAT'],
                ]);

                $label = "$slug (rtl=".var_export($rtl, true).')';
                $this->assertStringContainsString('Hummus', $html, $label);
                $this->assertStringContainsString('19.8', $html, $label);
                $this->assertStringContainsString('شيش طاووق', $html, $label);
                $this->assertStringContainsString('Casa Test', $html, $label);
                $this->assertStringContainsString('SAR', $html, "$label currency");
                $this->assertStringContainsString($rtl ? 'dir="rtl"' : 'dir="ltr"', $html, $label);
                // User text escaped — the raw script tag must never survive.
                $this->assertStringNotContainsString('<script>alert(1)</script>', $html, "$label escaping");
                $this->assertStringContainsString('&lt;script&gt;', $html, "$label escaping");
                // Standalone: no external fetches (http(s):// or //host in attrs).
                $this->assertDoesNotMatchRegularExpression(
                    '/(src|href)\s*=\s*["\'](https?:)?\/\//i', $html, "$label externals");
            }
        }
    }

    public function test_packages_declare_valid_manifests(): void
    {
        foreach (app(StudioTemplateService::class)->all() as $slug => $m) {
            $this->assertNotEmpty($m['aspects'], $slug);
            $this->assertArrayHasKey('accent', $m['colors'], $slug);
            $this->assertTrue(($m['rtl'] ?? false) === true, "$slug must be RTL-capable");
            $this->assertFileExists($m['path'].'/style.css', $slug);
        }
    }
}
