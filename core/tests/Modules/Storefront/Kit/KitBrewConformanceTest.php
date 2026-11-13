<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Tests\Support\KitTree;
use Tests\Support\TestPlans;
use Tests\Support\ThemeConformance;
use Tests\TestCase;

/**
 * P3-07: THEME-CONTRACT section 11 checks 1, 2, 3, 6 (static) and the per-file size budgets for the `brew` kit theme's REAL
 * Blade views — every preset x light/dark x en/ar, all six ported pages (home, item sheet, cart, checkout, track, states).
 * The browser half (axe, keyboard, CSP, Lighthouse, RTL screenshots) runs on the rendered trees (THEME_CONFORMANCE_OUT=<dir>,
 * then tools/themes/conformance/browser-job.mjs --root <dir>/<preset>-<scheme>).
 */
class KitBrewConformanceTest extends TestCase
{
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
        $this->seed(KitDemoSeeder::class);
        $store = Store::where('slug', 'bloom-coffee-demo')->firstOrFail();
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1']]);
        User::whereKey($store->user_id)->update(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::updateOrCreate(['user_id' => $store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
        StoreOption::updateStoreOption($store->id, 'store_template', 'brew');

        return $store;
    }

    public function test_every_preset_in_light_and_dark_in_en_and_ar_passes_the_static_gates(): void
    {
        $store = $this->demoStore();
        $presets = array_column(ThemeCustomizerService::schemaFor('brew')['presets'] ?? [], 'id');
        $this->assertSame(['matcha', 'bakery', 'dessert', 'clean'], $presets);

        $base = ThemeConformance::outDir();
        $failures = [];
        $checked = 0;
        foreach ($presets as $preset) {
            foreach (['light', 'dark'] as $scheme) {
                $tree = new KitTree($store, 'brew');
                $rendered = $tree->render($preset, $scheme);
                foreach (['en', 'ar'] as $locale) {
                    $this->assertSame(KitTree::PAGES, array_keys($rendered[$locale]), "{$preset}/{$scheme}/{$locale} renders every ported page");
                    foreach ($rendered[$locale] as $page => $html) {
                        $this->assertStringContainsString('data-theme-slug="brew"', $html, "{$preset}/{$scheme}/{$locale}/{$page}");
                        $this->assertStringContainsString($locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"', $html);
                        $this->assertStringContainsString('data-scheme="'.$scheme.'"', $html);
                    }
                }
                $dir = $base.'/'.$preset.'-'.$scheme;
                $tree->write($dir, $rendered);
                $r = ThemeConformance::runGates((string) ThemeConformance::python(), $dir, 'brew');
                $checked++;
                if ($r['code'] !== 0) {
                    $failures[] = "== {$preset} / {$scheme} ==\n".$r['output'];
                }
            }
        }

        if (getenv('THEME_CONFORMANCE_OUT') === false || getenv('THEME_CONFORMANCE_OUT') === '') {
            ThemeConformance::rrmdir($base);
        }
        $this->assertSame(8, $checked);
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /** the fixture-driven parity tree for the browser job's RTL pixel diff (kept only with THEME_CONFORMANCE_OUT) */
    public function test_the_parity_tree_renders_from_the_design_fixtures(): void
    {
        $store = $this->demoStore();
        $dir = ThemeConformance::outDir().'/parity';
        (new KitTree($store, 'brew'))->writeParity($dir);
        foreach (['en', 'ar'] as $locale) {
            foreach (['home', 'item', 'track', 'states'] as $page) {
                $html = (string) file_get_contents($dir."/prototypes/brew/{$locale}/{$page}.html");
                $this->assertStringContainsString('data-theme-slug="brew"', $html, "{$locale}/{$page}");
                $this->assertStringContainsString('proto.js', $html);
            }
        }
        $r = ThemeConformance::runGates((string) ThemeConformance::python(), $dir, 'brew');
        if (getenv('THEME_CONFORMANCE_OUT') === false || getenv('THEME_CONFORMANCE_OUT') === '') {
            ThemeConformance::rrmdir($dir);
        }
        $this->assertSame(0, $r['code'], $r['output']);
    }
}
