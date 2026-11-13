<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink\Bio;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Lane C · C10 sweep. The whole preset catalogue at once: all 38 present, each renders 200
 * under LTR and RTL, the CSP invariant holds across every bio blade AND css file, fonts come
 * from a documented Google-Fonts allowlist, and the picker gallery renders every thumbnail.
 */
class BioLinkSweepTest extends TestCase
{
    use RefreshDatabase;

    /** Every Google-Fonts family the 38 presets are allowed to request (documented). */
    private const FONT_ALLOWLIST = [
        'Manrope', 'Unbounded', 'Fraunces', 'IBM Plex Mono', 'Archivo', 'Bungee', 'Baloo 2',
        'Tajawal', 'Amiri', 'Cormorant Garamond', 'Orbitron', 'Playfair Display',
        'Zen Maru Gothic', 'Caveat', 'Poppins',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    /** @return array<int,array{slug:string,json:array}> */
    private function presets(): array
    {
        $out = [];
        foreach (glob(resource_path('views/bio/presets/*.json')) ?: [] as $file) {
            $out[] = ['slug' => basename($file, '.json'), 'json' => json_decode((string) File::get($file), true) ?: []];
        }

        return $out;
    }

    private function contract(array $json, bool $rtl): array
    {
        return [
            'store' => (object) ['id' => 9, 'slug' => 'demo', 'title' => 'Demo', 'bio_title' => 'Demo',
                'bio_description' => 'Bio', 'bio_main_image' => null, 'never_show_title' => 0],
            'branch' => (object) ['address' => '1 St', 'whatsapp' => '+966500000000', 'hours' => null, 'latitude' => 24.7, 'longitude' => 46.6],
            'preset' => $json,
            'tokens' => $json['tokens'] ?? [],
            'bioSettings' => ['title' => 'Demo', 'subtitle' => 'Branch', 'status_label' => 'OPEN',
                'countdown' => ['title' => 'Drop', 'target' => now()->addDay()->toIso8601String(), 'cta_label' => 'Wait', 'cta_url' => 'https://x.test']],
            'poweredBy' => 'Powered by MyMenu',
            'rtl' => $rtl,
            'source' => 'qr',
            'blocks' => [
                ['id' => 1, 'type' => 'menu', 'label' => 'Menu', 'url' => 'https://x.test', 'config' => ['sub' => 'Dishes']],
                ['id' => 2, 'type' => 'featured_dish', 'config' => ['title' => 'Special', 'price_label' => '30 SAR']],
                ['id' => 3, 'type' => 'video', 'config' => ['media' => 'https://cdn.test/c.mp4']],
                ['id' => 4, 'type' => 'capture', 'config' => ['text' => 'Join']],
                ['id' => 5, 'type' => 'socials', 'config' => ['links' => [['platform' => 'instagram', 'url' => 'https://i.test']]]],
            ],
        ];
    }

    public function test_exactly_38_presets_with_matching_thumbs(): void
    {
        $presets = $this->presets();
        $this->assertCount(38, $presets, 'all 38 presets authored');

        foreach ($presets as $p) {
            $this->assertFileExists(resource_path('views/bio/presets/thumbs/'.$p['slug'].'.blade.php'), $p['slug'].': thumb');
        }

        // every family declared by a preset has a layout blade
        foreach ($presets as $p) {
            $this->assertFileExists(resource_path('views/bio/layouts/'.$p['json']['family'].'.blade.php'), $p['slug'].': family layout');
        }
    }

    public function test_all_38_render_200_under_ltr_and_rtl(): void
    {
        foreach ($this->presets() as $i => $p) {
            $family = $p['json']['family'];
            foreach ([false => 'ltr', true => 'rtl'] as $rtl => $tag) {
                $rtl = (bool) $rtl;
                $path = '/__biosweep/'.$i.'/'.$tag;
                Route::get($path, fn () => view('bio.layouts.'.$family, $this->contract($p['json'], $rtl)))->middleware('web');
                $html = $this->get($path)->assertOk()->getContent();

                $this->assertStringContainsString('bio--'.$p['slug'], $html, $p['slug']." ($tag): marker");
                $this->assertStringContainsString('dir="'.($rtl || ! empty($p['json']['rtl_native']) ? 'rtl' : 'ltr').'"', $html, $p['slug']." ($tag): dir");
                $this->assertSame(1, substr_count($html, '<style'), $p['slug']." ($tag): one style");
                $this->assertSame(1, substr_count($html, '<script'), $p['slug']." ($tag): one script");
                $this->assertSame(0, preg_match_all('/<style(?![^>]*\bnonce\b)[ >]/i', $html), $p['slug']." ($tag): no un-nonced style");
                $this->assertStringNotContainsString('<iframe', $html, $p['slug']." ($tag): no iframe (G6)");
            }
        }
    }

    public function test_fonts_come_from_documented_allowlist(): void
    {
        $used = [];
        foreach ($this->presets() as $p) {
            foreach (($p['json']['fonts'] ?? []) as $font) {
                $fam = $font['family'] ?? '';
                $used[$fam] = true;
                $this->assertContains($fam, self::FONT_ALLOWLIST, $p['slug'].": font '$fam' is on the allowlist");
            }
        }
        // the allowlist has no dead entries — everything documented is actually used
        foreach (self::FONT_ALLOWLIST as $fam) {
            $this->assertArrayHasKey($fam, $used, "allowlisted font '$fam' is used by at least one preset");
        }
    }

    public function test_no_raw_style_or_script_tokens_across_bio_views_and_css(): void
    {
        $dir = resource_path('views/bio');
        $offenders = [];

        foreach (File::allFiles($dir) as $file) {
            $body = $file->getContents();
            $rel = str_replace($dir.'/', '', $file->getPathname());

            if (str_ends_with($file->getFilename(), '.blade.php')) {
                if (preg_match_all('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce\b)[ >]/i', $body, $m)) {
                    $offenders[] = $rel.' ('.count($m[0]).' un-nonced <script>)';
                }
                if (preg_match_all('/<style(?![^>]*\bnonce\b)[ >]/i', $body, $m)) {
                    $offenders[] = $rel.' ('.count($m[0]).' un-nonced <style>)';
                }
            } elseif (str_ends_with($file->getFilename(), '.css')) {
                if (preg_match_all('/<style|<script/i', $body, $m)) {
                    $offenders[] = $rel.' ('.count($m[0]).' raw tag token in css)';
                }
            }
        }

        $this->assertSame([], $offenders, "raw style/script tokens found:\n".implode("\n", $offenders));
    }

    public function test_picker_gallery_renders_every_thumbnail(): void
    {
        $html = view('bio.presets._gallery')->render();

        $this->assertSame(38, substr_count($html, 'data-preset='), 'gallery shows all 38 thumbs');
        $this->assertStringContainsString('data-preset="classic_flat"', $html);
        $this->assertStringContainsString('data-preset="majlis_gold"', $html);
        $this->assertSame(0, preg_match_all('/<style|<script/i', $html), 'gallery has no raw tags');
    }
}
