<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P6-154 — IDOR route-table drift guard.
 *
 * Every route that takes a path parameter must be classified in tests/Fixtures/idor-route-classification.json as
 * one of:
 *  - policy           an authorization gate decides (superadmin `admin`, `tenant.owns`, `can:`);
 *  - scoped-binding   the record is looked up inside the acting owner/staff/customer/token scope
 *                     (proven by CrossStoreIdorSweepTest / OwnerApiIdorCensusTest / BodyIdIdorTest);
 *  - signed           a signed URL or one-time token is checked;
 *  - public-by-design no login; the params are public slugs/ids, secret tokens or provider names.
 *
 * A new route with a parameter that is not in the file fails CI: classify it (and, for scoped-binding, make sure
 * the sweep covers it). Stale entries fail too, so the file stays the real route table. Vendor Livewire routes
 * (hash-prefixed URIs) are excluded.
 */
class IdorRouteClassificationTest extends TestCase
{
    private const FILE = __DIR__.'/../../Fixtures/idor-route-classification.json';

    private const CLASSES = ['policy', 'scoped-binding', 'signed', 'public-by-design'];

    private const AUTH_MW = ['auth', 'staff.dashboard', 'admin', 'tenant.owns', 'signed'];

    public function test_every_parameterised_route_is_classified(): void
    {
        $file = $this->classification();
        $routes = $this->population();

        $this->assertGreaterThan(500, count($routes), 'the guard must see the real route table');

        if (getenv('IDOR_CLASSIFY_DUMP')) { // helper for regenerating the file: prints the population as JSON
            $dump = [];
            foreach ($routes as $key => $r) {
                $dump[] = ['key' => $key, 'uri' => $r->uri(), 'mw' => $this->middleware($r), 'action' => $r->getActionName()];
            }
            fwrite(STDOUT, "\nIDORJSON".json_encode($dump, JSON_UNESCAPED_SLASHES)."\n");
        }

        $missing = array_values(array_diff(array_keys($routes), array_keys($file)));
        $this->assertSame([], $missing, 'Unclassified route(s) with a path parameter. Add each to tests/Fixtures/idor-route-classification.json as policy | scoped-binding | signed | public-by-design (and prove scoping in CrossStoreIdorSweepTest).');

        $stale = array_values(array_diff(array_keys($file), array_keys($routes)));
        $this->assertSame([], $stale, 'Classified route(s) that no longer exist: remove them from the classification file.');
    }

    public function test_classes_are_valid_and_reasoned(): void
    {
        $bad = [];
        foreach ($this->classification() as $key => $row) {
            if (! in_array($row['class'], self::CLASSES, true) || trim($row['why']) === '') {
                $bad[] = $key;
            }
        }
        $this->assertSame([], $bad, 'Every entry needs a class from '.implode('|', self::CLASSES).' and a reason (no "unresolved").');
    }

    public function test_classes_match_the_routes_middleware(): void
    {
        $file = $this->classification();
        $wrong = [];
        foreach ($this->population() as $key => $route) {
            $class = $file[$key]['class'] ?? null;
            $mw = $this->middleware($route);
            // `signed:relative` (a link built on another host than it is opened on) is signed middleware too.
            $signed = in_array('signed', $mw, true) || in_array('signed:relative', $mw, true);
            if ($class === 'signed' && ! $signed && ! $this->controllerChecksSignature($route)) {
                $wrong[] = "$key: classified signed, but no signed middleware or signature check";
            }
            if ($class === 'public-by-design' && $this->behindLogin($mw)) {
                $wrong[] = "$key: classified public-by-design, but it is behind login — classify it scoped-binding or policy";
            }
            if ($class !== 'signed' && $signed) {
                $wrong[] = "$key: has signed middleware — classify it signed";
            }
            if (in_array('admin', $mw, true) && $class !== 'policy' && $class !== 'signed') {
                $wrong[] = "$key: superadmin route — classify it policy";
            }
        }
        $this->assertSame([], $wrong);
    }

    public function test_the_guard_catches_a_new_unclassified_route(): void
    {
        Route::middleware(['web', 'auth'])->delete('__idor_drift/{order}', fn () => 'x')->name('__idor.drift');
        Route::getRoutes()->refreshNameLookups();

        $this->assertArrayHasKey('__idor.drift', $this->population());
        $this->assertArrayNotHasKey('__idor.drift', $this->classification());
    }

    /** @return array<string,array{class:string,why:string}> */
    private function classification(): array
    {
        $data = json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        return $data;
    }

    /** @return array<string,RoutingRoute> keyed by route name, or "METHODS uri" for unnamed routes */
    private function population(): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->parameterNames() === [] || str_starts_with($route->uri(), 'livewire')) {
                continue;
            }
            $key = $route->getName() ?: implode('|', array_values(array_diff($route->methods(), ['HEAD']))).' '.$route->uri();
            $out[$key] = $route;
        }

        return $out;
    }

    /** @return list<string> */
    private function middleware(RoutingRoute $route): array
    {
        return array_values(array_filter($route->middleware(), 'is_string')); // never gatherMiddleware(): it builds controllers
    }

    /** @param  list<string>  $mw */
    private function behindLogin(array $mw): bool
    {
        foreach ($mw as $m) {
            if (in_array($m, self::AUTH_MW, true) || str_starts_with($m, 'auth:')
                || (str_contains($m, 'TokenAuth') && ! str_contains($m, 'Optional'))) {
                return true;
            }
        }

        return false;
    }

    /** A signature / one-time token checked in the controller (constructor middleware or the action itself). */
    private function controllerChecksSignature(RoutingRoute $route): bool
    {
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            return false;
        }
        $class = explode('@', $action)[0];
        if (! class_exists($class)) {
            return false;
        }
        $src = (string) file_get_contents((string) (new \ReflectionClass($class))->getFileName());

        return str_contains($src, 'hasValidSignature') || str_contains($src, "middleware('signed')")
            || str_contains($src, 'BridgeToken::verify');
    }
}
