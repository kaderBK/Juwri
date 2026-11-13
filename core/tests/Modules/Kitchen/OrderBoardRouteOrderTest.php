<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use Juwri\Core\Rules\ReservedSlug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * PARITY-RUN T2: regression guard for the oldest routing trap in this codebase —
 * a public path registered AFTER `{slug}` is swallowed by the storefront
 * catch-all and 404s. `/board/{slug}` must resolve to the board.
 */
class OrderBoardRouteOrderTest extends TestCase
{
    public function test_board_routes_resolve_ahead_of_the_storefront_catch_all(): void
    {
        $board = Route::getRoutes()->match(Request::create('/board/some-store', 'GET'));
        $this->assertSame('board.show', $board->getName());

        $state = Route::getRoutes()->match(Request::create('/board/some-store/state', 'GET'));
        $this->assertSame('board.state', $state->getName());
    }

    public function test_board_routes_are_registered_before_the_catch_all(): void
    {
        $names = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $names[] = $route->getName() ?: $route->uri();
        }

        $board = array_search('board.show', $names, true);
        $catchAll = array_search('publicView', $names, true);

        $this->assertNotFalse($board, 'the board route is missing');
        $this->assertNotFalse($catchAll, 'the storefront catch-all is missing');
        $this->assertLessThan($catchAll, $board, '/board/{slug} must be registered before the {slug} catch-all');
    }

    /** …and no store may take the slug "board" out from under it. */
    public function test_board_is_a_reserved_slug(): void
    {
        $this->assertTrue(Validator::make(['slug' => 'Board'], ['slug' => [new ReservedSlug]])->fails());
    }

    /** The board must never be middleware-gated on a feature (403s every guest). */
    public function test_board_routes_carry_no_feature_middleware(): void
    {
        foreach (['board.show', 'board.state'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            foreach ($middleware as $entry) {
                if (! is_string($entry)) {
                    continue;
                }
                $this->assertStringNotContainsString('feature:', $entry, "[$name] must not be feature-gated in middleware");
                $this->assertStringNotContainsString('entitled:', $entry, "[$name] must not be entitlement-gated in middleware");
                $this->assertNotSame('auth', $entry, "[$name] is a public screen");
            }
        }
    }
}
