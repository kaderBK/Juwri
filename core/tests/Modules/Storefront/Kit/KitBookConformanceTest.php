<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Juwri\Modules\Laundry\Http\Controllers\User\LaundryThemeController;
use Juwri\Modules\Laundry\Services\Laundry\LaundryHolds;
use Juwri\Modules\Laundry\Services\Laundry\ServiceBookingService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Storefront\KitStorefront;
use Juwri\Modules\Verticals\Contracts\GoodsCartQuote;
use Juwri\Modules\Verticals\Services\Fulfillment\GoodsCartService;
use Tests\Modules\Laundry\Concerns\LaundryHoldFixtures;
use Tests\Support\BookStoreScenario;
use Tests\Support\KitTree;
use Tests\Support\ThemeConformance;
use Tests\TestCase;

/**
 * P3-11: THEME-CONTRACT section 11 checks 1, 2, 3, 6 (static) and the per-file size budgets for the `book` kit theme's REAL Blade
 * views — every preset x light/dark x en/ar, all the pages of the booking flow (store page, service sheet, choose a time, confirm,
 * booking page, reschedule, pay the deposit, the states board). The browser half (axe, keyboard, CSP, Lighthouse, RTL screenshots)
 * runs on the rendered trees (THEME_CONFORMANCE_OUT=<dir>, then tools/themes/conformance/browser-job.mjs --root <dir>/<preset>-<scheme>).
 */
class KitBookConformanceTest extends TestCase
{
    use BookStoreScenario;
    use LaundryHoldFixtures;
    use RefreshDatabase;

    public const PAGES = ['home', 'item', 'time', 'checkout', 'reschedule', 'track', 'pay', 'states'];

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
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_preset_in_light_and_dark_in_en_and_ar_passes_the_static_gates(): void
    {
        ['store' => $store, 'services' => $services] = $this->bookScenarioStore();
        $records = $this->bookScenarioRecords($store, $services);
        $slug = $store->slug;
        // the pay page lists the store's gateways: one is on, so the page has something to choose and press
        StoreOption::updateStoreOption($store->id, 'restaurant_wire_transfer_install', '1');
        $presets = array_column(ThemeCustomizerService::schemaFor('book')['presets'] ?? [], 'id');
        $this->assertSame(['calm', 'bright', 'clinic'], $presets);

        $base = ThemeConformance::outDir();
        $failures = [];
        $checked = 0;
        foreach ($presets as $preset) {
            foreach (['light', 'dark'] as $scheme) {
                $values = ThemeCustomizerService::presetValues(ThemeCustomizerService::schemaFor('book'), $preset) ?? [];
                $values['scheme.mode'] = $scheme;
                ThemeSetting::updateOrCreate(['store_id' => $store->id, 'template' => 'book'], ['settings' => $values]);

                $rendered = [];
                foreach (['en', 'ar'] as $locale) {
                    app()->setLocale($locale);
                    $kit = app(KitStorefront::class);
                    $http = Request::create('/'.$slug);
                    $svc = (string) $services[0]->public_id;
                    $rendered[$locale] = [
                        'home' => View::make('store_templates.book.index', $kit->home($store, $http, 'book'))->render(),
                        'item' => View::make('store_templates.book.index', $kit->home($store, Request::create('/'.$slug, 'GET', ['item' => $svc]), 'book'))->render(),
                        'time' => $this->bookStep(fn ($c, $r) => $c->times($slug, $r), ['services' => [$svc], 'date' => '2026-10-05']),
                        'checkout' => $this->bookStep(fn ($c, $r) => $c->details($slug, $records['hold'], $r)),
                        'reschedule' => $this->bookStep(fn ($c, $r) => $c->rescheduleTimes($slug, $records['confirmed'], $r), ['date' => '2026-10-06']),
                        'track' => $this->bookStep(fn ($c, $r) => $c->manage($slug, $records['confirmed'])),
                        'pay' => $this->bookStep(fn ($c, $r) => $c->pay($slug, $records['pending'])),
                        'states' => View::make('store_templates.book.states', $kit->home($store, $http, 'book'))->render(),
                    ];
                    $this->assertSame(self::PAGES, array_keys($rendered[$locale]), "{$preset}/{$scheme}/{$locale} renders every page");
                    foreach ($rendered[$locale] as $page => $html) {
                        $label = "{$preset}/{$scheme}/{$locale}/{$page}";
                        $this->assertStringContainsString('data-theme-slug="book"', $html, $label);
                        $this->assertTrue(str_contains($html, $locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"'), $label.' direction');
                        $this->assertStringContainsString('data-scheme="'.$scheme.'"', $html, $label);
                    }
                }
                app()->setLocale('en');
                $dir = $base.'/'.$preset.'-'.$scheme;
                $tree = new KitTree($store, 'book');
                $tree->write($dir, $rendered);
                $r = ThemeConformance::runGates((string) ThemeConformance::python(), $dir, 'book');
                $checked++;
                if ($r['code'] !== 0) {
                    $failures[] = "== {$preset} / {$scheme} ==\n".$r['output'];
                }
            }
        }

        if (getenv('THEME_CONFORMANCE_OUT') === false || getenv('THEME_CONFORMANCE_OUT') === '') {
            ThemeConformance::rrmdir($base);
        }
        $this->assertSame(6, $checked);
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /** the fixture-driven parity tree for the browser job's RTL pixel diff (kept only with THEME_CONFORMANCE_OUT) */
    public function test_the_parity_tree_renders_from_the_design_fixtures(): void
    {
        ['store' => $store] = $this->bookScenarioStore();
        $dir = ThemeConformance::outDir().'/parity';
        (new KitTree($store, 'book'))->writeParity($dir);
        foreach (['en', 'ar'] as $locale) {
            foreach (['home', 'item', 'time', 'checkout', 'reschedule', 'track', 'states'] as $page) {
                $html = (string) file_get_contents($dir."/prototypes/book/{$locale}/{$page}.html");
                $this->assertStringContainsString('data-theme-slug="book"', $html, "{$locale}/{$page}");
                $this->assertStringContainsString('proto.js', $html);
            }
        }
        $r = ThemeConformance::runGates((string) ThemeConformance::python(), $dir, 'book');
        if (getenv('THEME_CONFORMANCE_OUT') === false || getenv('THEME_CONFORMANCE_OUT') === '') {
            ThemeConformance::rrmdir($dir);
        }
        $this->assertSame(0, $r['code'], $r['output']);
    }

    /**
     * The laundry variant of the same theme (per-piece and per-kg services, pickup and delivery times with one hold, the confirm page, the
     * laundry order's tracking page) passes the same static gates, in every preset x scheme x language.
     */
    public function test_the_laundry_variant_passes_the_static_gates_too(): void
    {
        $this->laundryTest();
    }

    private function laundryTest(): void
    {
        $store = $this->laundryStore(['store_template' => 'book', 'scheduling_slot_capacity' => 5, 'menu_languages' => json_encode(['en', 'ar']), 'default_language' => 'en']);
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Laundry', 'active' => '1']);
        $shirt = $this->shirtIron($store);
        $wash = $this->washAndFold($store);
        Menu::query()->whereIn('id', [$shirt->id, $wash->id])->update(['category_id' => $category->id]);
        $slug = $store->slug;

        $token = app(GoodsCartService::class)->token(Request::create('/'));
        app(ServiceBookingService::class)->replaceLines($store, $token, [(string) $shirt->public_id => 3, (string) $wash->public_id => 2.5]);
        $made = app(LaundryHolds::class)->hold($store, 'delivery', $this->slotId(1, '10:00'), $this->slotId(2, '16:30'), 'conformance');
        session()->put('laundry.holds.'.$made['hold']->public_id, $made['token']);
        $order = app(ServiceBookingService::class)->book($store, $token, 'delivery', [
            'hold' => (string) $made['hold']->public_id, 'hold_token' => $made['token'], 'customer_name' => 'Noura', 'phone_number' => '+966500000001', 'address' => '12 Olaya St',
        ]);
        // a second hold for the confirm page (the first became the order)
        app(ServiceBookingService::class)->replaceLines($store, $token, [(string) $shirt->public_id => 3, (string) $wash->public_id => 2.5]);
        $second = app(LaundryHolds::class)->hold($store, 'delivery', $this->slotId(1, '12:00'), $this->slotId(2, '17:00'), 'conformance');
        session()->put('laundry.holds.'.$second['hold']->public_id, $second['token']);

        $step = function (callable $call, array $query = []): string {
            $request = Request::create('/step', 'GET', $query);
            $request->setLaravelSession(app('session.store'));
            $response = $call(app(LaundryThemeController::class), $request);
            $this->assertSame(200, $response->getStatusCode());

            return (string) $response->getContent();
        };

        $base = ThemeConformance::outDir();
        $failures = [];
        foreach (['calm', 'bright', 'clinic'] as $preset) {
            foreach (['light', 'dark'] as $scheme) {
                $values = ThemeCustomizerService::presetValues(ThemeCustomizerService::schemaFor('book'), $preset) ?? [];
                $values['scheme.mode'] = $scheme;
                ThemeSetting::updateOrCreate(['store_id' => $store->id, 'template' => 'book'], ['settings' => $values]);
                $rendered = [];
                foreach (['en', 'ar'] as $locale) {
                    app()->setLocale($locale);
                    $kit = app(KitStorefront::class);
                    $rendered[$locale] = [
                        'home' => View::make('store_templates.book.index', $kit->home($store, Request::create('/'.$slug), 'book'))->render(),
                        'time' => $step(fn ($c, $r) => $c->schedule($slug, $r, app(GoodsCartQuote::class)), ['type' => 'delivery', 'date' => $this->slotDate(1), 'return_date' => $this->slotDate(2), 'pickup' => $this->slotId(1, '10:00')]),
                        'checkout' => $step(fn ($c, $r) => $c->confirm($slug, (string) $second['hold']->public_id, $r, app(GoodsCartQuote::class))),
                        'track' => View::make('store_templates.book.track', $kit->track($store, $order->fresh(), 'book'))->render(),
                    ];
                    foreach ($rendered[$locale] as $page => $html) {
                        $label = "laundry {$preset}/{$scheme}/{$locale}/{$page}";
                        $this->assertStringContainsString('data-theme-slug="book"', $html, $label);
                        $this->assertTrue(str_contains($html, $locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"'), $label.' direction');
                    }
                }
                app()->setLocale('en');
                $dir = $base.'/laundry-'.$preset.'-'.$scheme;
                (new KitTree($store, 'book'))->write($dir, $rendered);
                $r = ThemeConformance::runGates((string) ThemeConformance::python(), $dir, 'book');
                if ($r['code'] !== 0) {
                    $failures[] = "== laundry {$preset} / {$scheme} ==\n".$r['output'];
                }
            }
        }
        if (getenv('THEME_CONFORMANCE_OUT') === false || getenv('THEME_CONFORMANCE_OUT') === '') {
            ThemeConformance::rrmdir($base);
        }
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    private function slotDate(int $days): string
    {
        return CarbonImmutable::now('UTC')->addDays($days)->format('Y-m-d');
    }
}
