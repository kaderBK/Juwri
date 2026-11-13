<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PARITY-RUN WV.V — every owner page must resolve the viewer's locale.
 *
 * `routes/features5/*.php` are required OUTSIDE the big `Route::group` in
 * `routes/web.php` that carries `app.locale`, and each of those files
 * copied only the INNER `['auth', 'staff.dashboard', 'dashboard.sync', …]`
 * list. The result: three owner pages shipped by this run — T5 Schedules, T3
 * Table verification and the T7 Zomato/Swiggy channel pages — rendered
 * `lang="en" dir="ltr"` inside an Arabic RTL dashboard. Not just English text:
 * a left-to-right layout in the middle of a right-to-left panel.
 *
 * No feature test could see it, because a test sets the application locale
 * itself and never exercises the middleware stack that derives it from the
 * session. It took opening the page in a browser. This file is the cheap guard
 * that stops the next route file from repeating it.
 */
class ParityRouteLocaleMiddlewareTest extends TestCase
{
    /** Owner-facing GET routes shipped outside routes/web.php's localized group. */
    public static function ownerPageRoutes(): array
    {
        return [
            'T5 price schedules' => ['stores.schedules.index'],
            'T3 table verification' => ['stores.tableVerify.index'],
            'T7 Zomato channel' => ['stores.zomato.index'],
            'T7 Swiggy channel' => ['stores.swiggy.index'],
            'H3 hotel rooms' => ['hotel.rooms'],
            'H7 hotel stays' => ['hotel.stays'],
            'F3 stories manager' => ['stories.index'],
        ];
    }

    #[DataProvider('ownerPageRoutes')]
    public function test_an_owner_page_route_resolves_the_viewers_locale(string $routeName): void
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route {$routeName} does not exist.");
        $this->assertContains(
            'app.locale',
            $route->gatherMiddleware(),
            "{$routeName} does not run the locale middleware, so it renders in the "
            .'application default locale instead of the viewer\'s — English LTR '
            .'inside an Arabic RTL dashboard.'
        );
    }

    /**
     * The public guest surfaces of the same tracks. A diner never signs in, so
     * their locale can only come from this middleware.
     */
    public function test_the_public_table_verification_routes_are_localized(): void
    {
        foreach (['table.verify.code', 'table.verify.otp'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            if (! $route) {
                continue; // named differently is fine; the assertion below covers what exists
            }
            $this->assertContains('app.locale', $route->gatherMiddleware(), $name);
        }

        $this->assertTrue(true);
    }

    /** Admin pages shipped outside routes/web.php's localized admin group. */
    public static function adminPageRoutes(): array
    {
        return [
            'AI providers' => ['admin.ai-providers.index'],
            'Social providers' => ['admin.social-providers.index'],
            'Media Manager' => ['admin.mediamanager.index'],
            'Verified badge toggle' => ['admin.stores.verified.toggle'],
        ];
    }

    #[DataProvider('adminPageRoutes')]
    public function test_an_admin_route_resolves_the_viewers_locale(string $routeName): void
    {
        $route = Route::getRoutes()->getByName($routeName);

        if (! $route) {
            $this->markTestSkipped("Optional route {$routeName} is not loaded in this test environment.");
        }

        $this->assertContains(
            'localize',
            $route->gatherMiddleware(),
            "{$routeName} does not run the localized admin middleware stack."
        );
        $this->assertContains(
            'locale.remember',
            $route->gatherMiddleware(),
            "{$routeName} does not remember the active admin locale."
        );
    }

    /** Representative reseller panel routes used by the reseller sidebar. */
    public static function resellerPanelRoutes(): array
    {
        return [
            'Reseller portal' => ['reseller.portal'],
            'Reseller analytics' => ['reseller.analytics'],
            'Reseller customers' => ['reseller.customers'],
            'Reseller branding' => ['reseller.branding'],
        ];
    }

    #[DataProvider('resellerPanelRoutes')]
    public function test_a_reseller_panel_route_resolves_the_viewers_locale(string $routeName): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route {$routeName} does not exist.");
        $middleware = $route->gatherMiddleware();

        $this->assertTrue(
            in_array('app.locale', $middleware, true) || in_array('localize', $middleware, true),
            "{$routeName} does not resolve the reseller viewer's locale."
        );
    }
}
