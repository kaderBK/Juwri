<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Two anonymous-route wiring regressions.
 *
 * 1. GET /feedback (HomeController@feedback, the public marketing page) was
 *    silently replaced by the owner-dashboard feedback inbox: the dashboard is
 *    bare at root, so `prefix('feedback')` registered later collided on the very
 *    same URI+method. Laravel keys its route table by "GET feedback", so the
 *    public route did not merely lose the race — its NAME disappeared, and every
 *    `Route::has('feedback')` CTA in the bought theme (J9) stopped rendering.
 *
 * 2. GET /driver (driver PWA shell) carried `feature:driver_app`. That gate
 *    resolves its subject from the logged-in OWNER; on an anonymous route the
 *    subject is null and EnsureFeatureEnabled redirects to the owner upsell, so
 *    no driver could ever open the app. Project rule: never put feature:/
 *    entitled: middleware on anonymous routes.
 */
class AnonymousRouteWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_public_feedback_route_exists_and_is_not_shadowed(): void
    {
        $this->assertTrue(Route::has('feedback'), 'The public GET /feedback route name is missing.');
        $this->assertTrue(Route::has('feedback.send'), 'The public POST /feedback route name is missing.');

        $get = Route::getRoutes()->match(Request::create('/feedback', 'GET'));
        $this->assertSame('feedback', $get->getName());
        $this->assertStringContainsString('HomeController@feedback', $get->getActionName());

        $store = Route::getRoutes()->match(Request::create('/feedback', 'POST'));
        $this->assertSame('feedback.send', $store->getName());
        $this->assertStringContainsString('HomeController@feedbackSend', $store->getActionName());
    }

    public function test_owner_feedback_inbox_moved_off_the_public_uri(): void
    {
        $this->assertTrue(Route::has('stores.feedback.index'));
        $this->assertSame('customer-feedback', Route::getRoutes()->getByName('stores.feedback.index')->uri());
    }

    public function test_driver_shell_carries_no_feature_middleware_and_renders_anonymously(): void
    {
        foreach (['driver.pwa.shell', 'driver.fleet.shell'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Missing route [$name].");

            foreach ($route->gatherMiddleware() as $mw) {
                $this->assertFalse(
                    is_string($mw) && (str_starts_with($mw, 'feature:') || str_starts_with($mw, 'entitled:')),
                    "Anonymous route [$name] must not carry owner-subject middleware [$mw]."
                );
            }
        }

        // driver_app is plan-gated and nothing is enabled for a guest: still 200.
        $this->get('/driver')->assertOk();
        $this->get('/driver-app')->assertOk();
    }
}
