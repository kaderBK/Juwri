<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink\Bio;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lane C · presets. Discovers every bio/presets/{slug}.json, validates its schema,
 * renders it as a full page through its declared family (harness route), checks the
 * RTL contract, and renders its dashboard thumbnail. Auto-covers presets as C6–C9 land.
 */
class BioLinkPresetTest extends TestCase
{
    use RefreshDatabase;

    private const FAMILIES = ['list', 'menu_card', 'store_rows', 'bento', 'photo_hero', 'social_grid',
        'chat', 'story_card', 'broadsheet', 'countdown', 'swiss', 'brutalist', 'type_lite'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    /** @return array<string,array{0:string,1:array}> slug => [slug, decoded json] */
    public static function presetProvider(): array
    {
        $dir = __DIR__.'/../../../../resources/views/bio/presets';
        $out = [];
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $slug = basename($file, '.json');
            $json = json_decode((string) file_get_contents($file), true);
            $out[$slug] = [$slug, is_array($json) ? $json : []];
        }

        return $out ?: ['__none__' => ['__none__', []]];
    }

    private function contractFor(array $preset, array $overrides = []): array
    {
        $tokens = is_array($preset['tokens'] ?? null) ? $preset['tokens'] : [];

        return array_merge([
            'store' => (object) ['id' => 7, 'slug' => 'demo', 'title' => 'Demo', 'bio_title' => 'Demo',
                'bio_description' => 'A demo restaurant bio', 'bio_main_image' => null, 'never_show_title' => 0],
            'branch' => (object) ['address' => '1 St', 'whatsapp' => '+966500000000', 'hours' => null, 'latitude' => 24.7, 'longitude' => 46.6],
            'preset' => $preset,
            'tokens' => $tokens,
            'bioSettings' => ['title' => 'Demo', 'subtitle' => 'Two branches', 'status_label' => 'OPEN NOW',
                'countdown' => ['label' => 'Drops in', 'title' => 'Dinner', 'target' => now()->addDays(2)->toIso8601String(), 'cta_label' => 'Waitlist', 'cta_url' => 'https://x.test']],
            'poweredBy' => 'Powered by MyMenu',
            'rtl' => false,
            'source' => 'direct',
            'blocks' => [
                ['id' => 1, 'type' => 'menu', 'label' => 'Menu', 'url' => 'https://x.test/m', 'config' => ['sub' => 'All dishes']],
                ['id' => 2, 'type' => 'featured_dish', 'config' => ['title' => 'House Special', 'subtitle' => 'This week', 'price_label' => '38 SAR']],
                ['id' => 3, 'type' => 'menu_item', 'label' => 'Signature Plate', 'config' => ['price_label' => '24 SAR', 'sub' => 'Chef pick']],
                ['id' => 4, 'type' => 'hours', 'config' => ['rows' => [['label' => 'Thu', 'value' => '12:00 – 00:30', 'now' => true]]]],
                ['id' => 5, 'type' => 'announcement', 'config' => ['text' => 'Open all week']],
                ['id' => 6, 'type' => 'socials', 'config' => ['links' => [['platform' => 'instagram', 'url' => 'https://i.test'], ['platform' => 'whatsapp', 'url' => 'https://w.test']]]],
            ],
        ], $overrides);
    }

    #[DataProvider('presetProvider')]
    public function test_preset_json_schema_is_valid(string $slug, array $json): void
    {
        if ($slug === '__none__') {
            $this->markTestSkipped('no presets yet');
        }

        $this->assertSame($slug, $json['slug'] ?? null, "$slug: slug must match filename");
        $this->assertNotEmpty($json['name'] ?? '', "$slug: has a name");
        $this->assertContains($json['family'] ?? '', self::FAMILIES, "$slug: family is one of the 13");
        $this->assertContains($json['tier'] ?? '', ['basic', 'pro'], "$slug: tier is basic|pro");
        $this->assertIsArray($json['fonts'] ?? null, "$slug: fonts array present");
        $this->assertNotEmpty($json['fonts'], "$slug: at least one font");
        $tokens = $json['tokens'] ?? [];
        $this->assertIsArray($tokens, "$slug: tokens is an object");
        foreach (['--bg', '--ink', '--accent'] as $req) {
            $this->assertArrayHasKey($req, $tokens, "$slug: token $req present");
        }
        // residual css, if present, stays within the SPEC's ≤40-line budget and carries no raw tags
        $cssFile = resource_path('views/bio/presets/css/'.$slug.'.css');
        if (File::exists($cssFile)) {
            $css = File::get($cssFile);
            $this->assertLessThanOrEqual(44, substr_count($css, "\n") + 1, "$slug: residual css ≤ ~40 lines");
            $this->assertSame(0, preg_match_all('/<style|<script/i', $css), "$slug: residual css has no raw tags");
        }
        // a thumbnail blade exists for the picker
        $this->assertFileExists(resource_path('views/bio/presets/thumbs/'.$slug.'.blade.php'), "$slug: thumb blade exists");
    }

    #[DataProvider('presetProvider')]
    public function test_preset_renders_as_a_full_page(string $slug, array $json): void
    {
        if ($slug === '__none__') {
            $this->markTestSkipped('no presets yet');
        }

        $family = $json['family'];
        $contract = $this->contractFor($json);
        $path = '/__biopreset/'.$slug;
        Route::get($path, fn () => view('bio.layouts.'.$family, $contract))->middleware('web');
        $html = $this->get($path)->assertOk()->getContent();

        $this->assertStringContainsString('bio--'.$slug, $html, "$slug: preset marker class");
        $this->assertStringContainsString('fam-'.$family, $html, "$slug: family class");
        $this->assertStringContainsString('--accent:', $html, "$slug: tokens emitted");
        $this->assertStringContainsString('fonts.googleapis.com/css2?family=', $html, "$slug: fonts link");
        $this->assertSame(1, substr_count($html, '<style'), "$slug: one style tag");
        $this->assertSame(1, substr_count($html, '<script'), "$slug: one script tag");
        $this->assertSame(0, preg_match_all('/<style(?![^>]*\bnonce\b)[ >]/i', $html), "$slug: no un-nonced style");
        $this->assertSame(0, preg_match_all('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce\b)[ >]/i', $html), "$slug: no un-nonced script");
        $this->assertStringNotContainsString('<iframe', $html, "$slug: no iframe");

        // RTL: rtl_native forces rtl even under an LTR contract; every preset survives dir=rtl.
        if (! empty($json['rtl_native'])) {
            $this->assertStringContainsString('dir="rtl"', $html, "$slug: rtl_native renders rtl");
        }
        $rtlPath = $path.'-rtl';
        Route::get($rtlPath, fn () => view('bio.layouts.'.$family, $this->contractFor($json, ['rtl' => true])))->middleware('web');
        $this->assertStringContainsString('dir="rtl"', $this->get($rtlPath)->assertOk()->getContent(), "$slug: rtl under /ar");
    }

    #[DataProvider('presetProvider')]
    public function test_preset_thumbnail_renders(string $slug, array $json): void
    {
        if ($slug === '__none__') {
            $this->markTestSkipped('no presets yet');
        }

        $html = view('bio.presets.thumbs.'.$slug)->render();
        $this->assertStringContainsString('data-preset="'.$slug.'"', $html, "$slug: thumb carries slug");
        $this->assertStringContainsString('data-tier="'.$json['tier'].'"', $html, "$slug: thumb carries tier");
        $this->assertSame(0, preg_match_all('/<style|<script/i', $html), "$slug: thumb has no raw tags");
    }
}
