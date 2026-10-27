<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\App;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Http\Middleware\AddCspHeaders;
use Juwri\Core\Http\Middleware\AddDashboardCspHeaders;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Juwri\Modules\Storefront\Http\Middleware\EnforceStorefrontCspPerTemplate;
use Tests\Modules\Pos\OfflineTillFixture;
use Tests\TestCase;

/**
 * P6-122: the fleet driver app, the store driver app, the Theme Store, the App Store, the partner dashboard and the POS
 * register are served with the ENFORCED nonce policy (csp.app / csp.dashboard), not just `frame-ancestors`. The POS
 * register widens connect-src for its print bridges only. A ratchet keeps every other GET web route that still has no
 * CSP middleware on a known list, so a new page cannot ship without one.
 */
class AppSurfacesCspTest extends TestCase
{
    use OfflineTillFixture;
    use RefreshDatabase;

    private const CSP_MIDDLEWARE = ['csp.dashboard', 'csp.marketing', 'csp.app', AddCspHeaders::class,
        AddDashboardCspHeaders::class, EnforceStorefrontCspPerTemplate::class];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['csp.enabled' => true, 'csp.enforce_dashboard' => true, 'settings.admin_allow_driver_app' => true]);
    }

    /** The enforced policy: nonce script-src, no inline handlers, no bare unsafe-inline for scripts; the nonce is in the body. */
    private function assertEnforced(TestResponse $res, string $what): string
    {
        $this->assertFalse($res->headers->has('Content-Security-Policy-Report-Only'), $what.': not report-only');
        $csp = (string) $res->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self'[^;]*'nonce-([^']+)'/", $csp, $what);
        $this->assertStringContainsString("script-src-attr 'none'", $csp, $what);
        $this->assertStringContainsString("object-src 'none'", $csp, $what);
        $this->assertDoesNotMatchRegularExpression("/script-src [^;]*'unsafe-inline'/", $csp, $what);
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        if (str_contains((string) $res->getContent(), '<script nonce=') || str_contains((string) $res->getContent(), '<style nonce=')) {
            $this->assertStringContainsString('nonce="'.$m[1].'"', (string) $res->getContent(), $what.': the page nonce matches the header');
        }

        return $csp;
    }

    public function test_the_route_groups_carry_the_csp_middleware(): void
    {
        foreach (['driver.pwa.shell', 'driver.fleet.shell', 'marketplace.index', 'marketplace.show', 'apps.store.index',
            'apps.store.show', 'pos.index', 'pos.offline.exceptions.screen', 'partner.overview', 'partner.themes.edit'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertNotEmpty(array_intersect(self::CSP_MIDDLEWARE, $route->gatherMiddleware()), $name.' has CSP middleware');
        }
        // the public surfaces must not become dashboard routes for EnforceHostSplit (csp.dashboard marks them)
        foreach (['driver.pwa.shell', 'driver.fleet.shell', 'marketplace.index', 'apps.store.index', 'pos.index'] as $name) {
            $this->assertNotContains('csp.dashboard', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_the_driver_apps_are_served_with_the_enforced_policy(): void
    {
        $this->assertEnforced($this->get('/driver')->assertOk(), '/driver');
        $this->assertEnforced($this->get('/driver-app')->assertOk(), '/driver-app');
    }

    public function test_the_theme_store_and_app_store_are_served_with_the_enforced_policy(): void
    {
        $theme = Theme::create(['name' => 'Csp Cafe', 'slug' => 'csp-cafe', 'status' => 'published', 'price' => 0, 'category' => 'cafe', 'tagline' => 'T']);
        $theme->versions()->create(['semver' => '1.0.0', 'manifest' => ['t' => 1], 'checksum' => hash('sha256', 'x'), 'status' => 'published']);
        App::create(['name' => 'Csp App', 'slug' => 'csp-app', 'scopes' => ['menu:read'], 'status' => 'approved', 'category' => 'ops',
            'tagline' => 'T', 'billing_type' => 'free', 'price' => 0, 'billing_period_days' => 30]);

        $this->assertEnforced($this->get('/marketplace')->assertOk(), '/marketplace');
        $this->assertEnforced($this->get('/marketplace/csp-cafe')->assertOk(), '/marketplace/{slug}');
        $this->assertEnforced($this->get('/apps/store')->assertOk(), '/apps/store');
        $this->assertEnforced($this->get('/apps/store/csp-app')->assertOk(), '/apps/store/{slug}');
    }

    public function test_the_pos_register_is_enforced_and_allows_only_its_print_bridges(): void
    {
        $t = $this->make();
        PrinterProfile::create(['store_id' => $t['store']->id, 'name' => 'Bar', 'transport' => 'bridge', 'bridge_url' => 'http://192.168.1.50:9100/',
            'width_mm' => 80, 'events' => ['paid'], 'station_ids' => [], 'auto_print' => true, 'active' => true, 'copies' => 1]);
        PrinterProfile::create(['store_id' => $t['store']->id, 'name' => 'Evil', 'transport' => 'bridge', 'bridge_url' => "http://x.test; script-src 'unsafe-inline'",
            'width_mm' => 80, 'events' => ['paid'], 'station_ids' => [], 'auto_print' => true, 'active' => true, 'copies' => 1]);

        $csp = $this->assertEnforced($this->asStaff($t['cashier'])->get(route('pos.index'))->assertOk(), '/pos');
        $this->assertMatchesRegularExpression('#connect-src [^;]*http://127\.0\.0\.1:8543#', $csp, 'the loopback print bridge');
        $this->assertMatchesRegularExpression('#connect-src [^;]*http://192\.168\.1\.50:9100#', $csp, "the store's own bridge");
        $this->assertStringNotContainsString('x.test', $csp, 'a bridge URL that is not a plain origin is dropped');
        $this->assertSame(1, substr_count($csp, 'script-src '), 'no directive was injected');
    }

    public function test_extra_connect_origins_are_reduced_to_plain_origins(): void
    {
        $this->assertSame('http://127.0.0.1:8543', AddDashboardCspHeaders::origin('http://127.0.0.1:8543/print?x=1'));
        $this->assertSame('https://bridge.example', AddDashboardCspHeaders::origin('HTTPS://Bridge.Example/'));
        $this->assertNull(AddDashboardCspHeaders::origin('javascript:alert(1)'));
        $this->assertNull(AddDashboardCspHeaders::origin('http://a.test;script-src *'));
        $this->assertNull(AddDashboardCspHeaders::origin('*'));

        $request = Request::create('/pos', 'GET');
        $request->attributes->set(AddDashboardCspHeaders::EXTRA_CONNECT, ['http://10.0.0.2:8543', 'data:x']);
        $csp = (string) new AddDashboardCspHeaders()->handle($request, fn () => new Response('ok'))->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression('#connect-src [^;]*http://10\.0\.0\.2:8543#', $csp);
        $this->assertStringNotContainsString('data:x', $csp);
    }

    /**
     * Ratchet: a GET web route with no CSP middleware must be on the known list (tests/Feature/Fixtures/csp-uncovered-routes.txt).
     * Covering one removes it from the list; a new uncovered page fails here.
     */
    public function test_no_new_web_page_ships_without_a_csp_middleware(): void
    {
        // one route per line; text after `#` is the reason it may stay without a CSP middleware (JSON, redirect, file…)
        $known = array_values(array_filter(array_map(fn (string $l) => trim(explode('#', $l, 2)[0]), file(__DIR__.'/Fixtures/csp-uncovered-routes.txt') ?: [])));
        $uncovered = [];
        foreach (Route::getRoutes() as $route) {
            // a middleware with arguments (AddCspHeaders:SomePolicy) counts by its name
            $mw = array_map(fn (string $m) => explode(':', $m, 2)[0], array_values(array_filter($route->gatherMiddleware(), 'is_string')));
            if (! in_array('GET', $route->methods(), true) || ! in_array('web', $mw, true) || str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'livewire-')) {
                continue;
            }
            if (array_intersect(self::CSP_MIDDLEWARE, $mw) === []) {
                $uncovered[] = $route->uri();
            }
        }
        if (getenv('CSP_DUMP')) {
            file_put_contents('/tmp/lane-d-uncovered.txt', implode("\n", array_unique($uncovered))."\n");
        }
        $this->assertSame([], array_values(array_diff($uncovered, $known)), 'new GET web routes without CSP middleware');
        $this->assertSame([], array_values(array_diff($known, $uncovered)), 'covered now: remove these from csp-uncovered-routes.txt');
        foreach (['driver', 'driver-app', 'marketplace', 'apps/store', 'pos', 'partner'] as $covered) {
            $this->assertNotContains($covered, $known, $covered.' is covered now');
        }
    }
}
