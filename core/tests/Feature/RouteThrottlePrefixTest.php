<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\AisleStore;
use Tests\TestCase;

/**
 * `throttle:N,M` without a prefix keys the counter on domain|ip (or user) ONLY, so every such route of a visitor shared ONE bucket: a
 * storefront page that polled a few throttled endpoints used up the allowance of the login / OTP / password-reset routes (found by the
 * p3-aisle-retail e2e journey, 2026-10-06). Every numeric throttle now carries its own prefix (`throttle:N,M,<prefix>`); a named limiter
 * (`throttle:name`, RateLimiter::for) keys itself and needs none.
 */
class RouteThrottlePrefixTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_numeric_throttle_on_every_registered_route_has_a_prefix(): void
    {
        $bad = [];
        foreach (Route::getRoutes() as $route) {
            // the Livewire package registers its own upload route (`throttle:60,1`); it is not ours to prefix
            if (str_starts_with($route->uri(), 'livewire')) {
                continue;
            }
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_contains($middleware, 'throttle')) {
                    continue;
                }
                // `throttle:60,1[,prefix]` (alias) or `Illuminate\Routing\Middleware\ThrottleRequests:60,1[,prefix]` (class)
                if (preg_match('/(?:^throttle|ThrottleRequests):(.+)$/', $middleware, $m) !== 1) {
                    continue;
                }
                $params = explode(',', $m[1]);
                $numeric = count($params) >= 2 && ctype_digit($params[0]);
                if ($numeric && (count($params) < 3 || trim($params[2]) === '')) {
                    $bad[] = ($route->getName() ?: $route->uri()).' ['.implode('|', $route->methods()).'] '.$middleware;
                }
            }
        }

        $this->assertSame([], $bad, "numeric throttles without a prefix share one counter per visitor:\n".implode("\n", array_slice($bad, 0, 40)));
    }

    public function test_storefront_traffic_no_longer_uses_up_the_customer_otp_allowance(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        AisleStore::make();

        // the customer OTP request allows 6 a minute; before the prefixes, eight storefront calls had already filled the shared bucket
        for ($i = 0; $i < 8; $i++) {
            $this->getJson('/dune-and-co-demo/wishlist')->assertOk();
        }
        $res = $this->postJson('/dune-and-co-demo/customer/otp/request', []);

        $this->assertNotSame(429, $res->getStatusCode(), 'the OTP request was throttled by unrelated storefront traffic');
    }

    /**
     * Guesses at one OTP are counted across EVERY verify entry point: the web customer login and the customer-app API verify share the
     * `otp-verify` limit (10 a minute), so splitting attempts over two endpoints does not double the allowance.
     */
    public function test_otp_verify_attempts_spread_over_two_endpoints_hit_one_shared_limit(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $store = AisleStore::make()->store;
        $web = '/dune-and-co-demo/customer/otp/verify';
        $api = '/api/v1/stores/'.$store->id.'/customer/otp/verify';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($web, ['phone' => '+966500000001', 'code' => '000000']);
            $this->postJson($api, ['phone' => '+966500000001', 'code' => '000000']);
        }

        // ten attempts spent in total: an eleventh on EITHER endpoint is refused for traffic
        $this->postJson($web, ['phone' => '+966500000001', 'code' => '000000'])->assertStatus(429);
        $this->postJson($api, ['phone' => '+966500000001', 'code' => '000000'])->assertStatus(429);
    }
}
