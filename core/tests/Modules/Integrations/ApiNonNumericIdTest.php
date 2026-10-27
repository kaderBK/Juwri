<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * P4-17 ZAP scan: public v1 routes answered 500 (Application Error Disclosure) when a path id was not a number — the
 * spider requested the placeholder names (`/api/v1/menu/storeId`, `/api/v1/stores/id/customer/login`) and
 * the int-typed controller argument threw a TypeError. Every api/v1 route, with a non-numeric value in each path
 * parameter, must answer a client error (or a success), never a 500.
 */
class ApiNonNumericIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_api_v1_route_is_a_server_error_for_a_malformed_path_parameter(): void
    {
        $this->withoutMiddleware([Installed::class]);
        $bad = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/') || ! str_contains($uri, '{')) {
                continue;
            }
            $path = '/'.preg_replace('/\{(\w+)\??\}/', 'x$1', $uri);
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $res = $this->json($method, $path, []);
                if ($res->getStatusCode() >= 500) {
                    $bad[] = "$method $path: ".substr((string) ($res->exception?->getMessage() ?? ''), 0, 160);
                }
                $this->app['auth']->forgetGuards();
            }
        }
        $this->assertSame([], $bad);
    }
}
