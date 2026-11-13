<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Tests\Support\BasketStore;
use Tests\Support\KitTree;
use Tests\Support\PinsMiddayClock;
use Tests\Support\ThemeConformance;
use Tests\TestCase;

/**
 * P3-09: THEME-CONTRACT section 11 checks 1, 2, 3, 6 (static) and the per-file size budgets for the `basket` (grocery) kit theme's REAL
 * Blade views — every preset x light/dark x en/ar, all ported pages (home, product page, cart, checkout, track, wishlist, states, kiosk).
 * The browser half (axe, keyboard, CSP, Lighthouse, RTL screenshots) runs on the rendered trees (THEME_CONFORMANCE_OUT=<dir>,
 * then tools/themes/conformance/browser-job.mjs --root <dir>/<preset>-<scheme>).
 */
class KitBasketConformanceTest extends TestCase
{
    use PinsMiddayClock;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (ThemeConformance::python() === null) {
            $this->markTestSkipped('python3 is not installed on this machine (the conformance gates are Python 3 stdlib).');
        }
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function demoStore(): Store
    {
        return BasketStore::make()->store;
    }

    public function test_every_preset_in_light_and_dark_in_en_and_ar_passes_the_static_gates(): void
    {
        $store = $this->demoStore();
        $presets = array_column(ThemeCustomizerService::schemaFor('basket')['presets'] ?? [], 'id');
        $this->assertSame(['fresh', 'market', 'night'], $presets);

        $base = ThemeConformance::outDir();
        $failures = [];
        $checked = 0;
        foreach ($presets as $preset) {
            foreach (['light', 'dark'] as $scheme) {
                $tree = new KitTree($store, 'basket');
                $rendered = $tree->render($preset, $scheme);
                foreach (['en', 'ar'] as $locale) {
                    $this->assertSame(KitTree::PAGES, array_keys($rendered[$locale]), "{$preset}/{$scheme}/{$locale} renders every ported page");
                    foreach ($rendered[$locale] as $page => $html) {
                        $this->assertStringContainsString('data-theme-slug="basket"', $html, "{$preset}/{$scheme}/{$locale}/{$page}");
                        $this->assertStringContainsString($locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"', $html);
                        $this->assertStringContainsString('data-scheme="'.$scheme.'"', $html);
                    }
                }
                $dir = $base.'/'.$preset.'-'.$scheme;
                $tree->write($dir, $rendered);
                $r = ThemeConformance::runGates((string) ThemeConformance::python(), $dir, 'basket');
                $checked++;
                if ($r['code'] !== 0) {
                    $failures[] = "== {$preset} / {$scheme} ==\n".$r['output'];
                }
            }
        }

        if (getenv('THEME_CONFORMANCE_OUT') === false || getenv('THEME_CONFORMANCE_OUT') === '') {
            ThemeConformance::rrmdir($base);
        }
        $this->assertSame(6, $checked, '3 presets x light/dark');
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /** the fixture-driven parity tree for the browser job's RTL pixel diff (kept only with THEME_CONFORMANCE_OUT) */
    public function test_the_parity_tree_renders_from_the_design_fixtures(): void
    {
        $store = $this->demoStore();
        $dir = ThemeConformance::outDir().'/parity';
        (new KitTree($store, 'basket'))->writeParity($dir);
        foreach (['en', 'ar'] as $locale) {
            foreach (['home', 'item', 'track', 'states', 'kiosk'] as $page) {
                $html = (string) file_get_contents($dir."/prototypes/basket/{$locale}/{$page}.html");
                $this->assertStringContainsString('data-theme-slug="basket"', $html, "{$locale}/{$page}");
                $this->assertStringContainsString('proto.js', $html);
            }
        }
        $r = ThemeConformance::runGates((string) ThemeConformance::python(), $dir, 'basket');
        if (getenv('THEME_CONFORMANCE_OUT') === false || getenv('THEME_CONFORMANCE_OUT') === '') {
            ThemeConformance::rrmdir($dir);
        }
        $this->assertSame(0, $r['code'], $r['output']);
    }
}
