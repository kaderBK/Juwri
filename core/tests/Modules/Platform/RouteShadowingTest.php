<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * WIRING-RUN A0.
 *
 * routes/web.php ends with a storefront catch-all — GET /{slug} → StoreController@publicView.
 * Laravel matches in registration order, so ANY single-segment GET route registered after
 * that line is dead: the catch-all claims the URL first and the request 404s as an unknown
 * restaurant slug. That is exactly how the stories admin page shipped broken — its route
 * file was required ~400 lines below the catch-all, so /stories never reached
 * StoryController@index even though `route:list` showed it registered.
 *
 * `route:list` cannot reveal this; only real matching can. This test walks every registered
 * single-segment GET route and asserts the router actually resolves it to itself.
 */
class RouteShadowingTest extends TestCase
{
    public function test_no_single_segment_get_route_is_shadowed_by_the_storefront_catch_all(): void
    {
        $shadowed = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            // Only literal one-segment GET paths can collide with /{slug}.
            if (str_contains($uri, '/') || str_contains($uri, '{')) {
                continue;
            }
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if ($route->getName() === 'publicView') {
                continue;
            }

            try {
                $matched = Route::getRoutes()->match(Request::create('/'.$uri, 'GET'));
            } catch (\Throwable $e) {
                continue; // unroutable in the test env (host/domain constraints) — not a shadowing bug
            }

            if ($matched->getName() !== $route->getName()) {
                $shadowed[] = sprintf(
                    '/%s declared as [%s] but resolves to [%s]',
                    $uri,
                    $route->getName() ?: '(unnamed)',
                    $matched->getName() ?: '(unnamed)'
                );
            }
        }

        $this->assertSame([], $shadowed, "Route(s) shadowed by the {slug} catch-all — register them BEFORE it:\n".implode("\n", $shadowed));
    }
}
