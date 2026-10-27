<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P6-18: route rate-limit + auth-guard sweep. Reads the route table only (no requests).
 *  (a) a state-changing route must carry an auth/signature guard OR a throttle;
 *  (b) login / password / OTP / 2FA / passkey / PIN / signup / webhook / IPN writes must carry a throttle;
 *  (d) every admin/* route must pass AdminMiddleware.
 * Store scoping of owner/staff routes (c) is covered by the RouteTenantScopeCensus suite.
 * Justified exceptions live in tests/Support/route-guard-allowlist.php, each with a reason.
 */
class RouteGuardSweepTest extends TestCase
{
    private const AUTH = [
        'Authenticate', 'ApiTokenAuth', 'StorefrontTokenAuth', 'CustomerTokenAuth', 'StaffTokenAuth', 'DriverTokenAuth',
        'RwgAuth', 'ValidateSignature', 'EnsureOwnerAppToken', 'McpOwnerAuth', 'EnsureTableSessionVerified',
        'RestrictedDocsAccess', 'AdminSessionGuard', 'AdminMiddleware',
    ];

    private const THROTTLE = ['ThrottleRequests', 'ThrottleRequestsWithRedis', 'AbuseRateLimit', 'BranchRateLimit'];

    private const SENSITIVE = '#(^|/)(login|password|otp|two-factor|2fa|passkeys?|pin|signup|register|webhook|ipn)(/|$)#i';

    /** @return list<string> */
    private function middleware(RoutingRoute $route): array
    {
        return array_values(array_filter(
            app('router')->gatherRouteMiddleware($route),
            'is_string',
        ));
    }

    /** @param list<string> $stack @param list<string> $names */
    private function has(array $stack, array $names): bool
    {
        foreach ($stack as $mw) {
            $class = explode(':', $mw, 2)[0];
            foreach ($names as $n) {
                if ($class === $n || str_ends_with($class, '\\'.$n)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function key(RoutingRoute $route): string
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        return ($methods[0] ?? 'GET').' '.$route->uri();
    }

    private function writes(RoutingRoute $route): bool
    {
        // Route::redirect()/permanentRedirect() answers every verb with a redirect and changes no state
        if (str_contains((string) $route->getActionName(), 'RedirectController')) {
            return false;
        }

        return array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];
    }

    public function test_every_state_changing_route_is_guarded_or_throttled(): void
    {
        $allow = (require base_path('tests/Support/route-guard-allowlist.php'))['unguarded'];
        $bad = [];
        foreach (Route::getRoutes() as $route) {
            // Livewire's own endpoint (hashed path): CSRF + component checksum; each component authorizes itself
            if (! $this->writes($route) || isset($allow[$this->key($route)]) || str_starts_with($route->uri(), 'livewire')) {
                continue;
            }
            $mw = $this->middleware($route);
            if (! $this->has($mw, self::AUTH) && ! $this->has($mw, self::THROTTLE)) {
                $bad[] = $this->key($route);
            }
        }

        $this->assertSame([], $bad, "state-changing routes with no auth guard and no throttle (add one, or allow-list with a reason):\n".implode("\n", $bad));
    }

    public function test_auth_otp_pay_and_webhook_writes_are_throttled(): void
    {
        $allow = (require base_path('tests/Support/route-guard-allowlist.php'))['unthrottled_auth'];
        $bad = [];
        foreach (Route::getRoutes() as $route) {
            if (! $this->writes($route) || isset($allow[$this->key($route)])) {
                continue;
            }
            $sensitive = preg_match(self::SENSITIVE, $route->uri()) === 1
                || ($route->getName() === 'payment.pay');
            if ($sensitive && ! $this->has($this->middleware($route), self::THROTTLE)) {
                $bad[] = $this->key($route);
            }
        }

        $this->assertSame([], $bad, "auth/OTP/pay/webhook writes without a throttle:\n".implode("\n", $bad));
    }

    public function test_every_admin_route_passes_the_admin_guard(): void
    {
        $bad = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'admin/') && $route->uri() !== 'admin') {
                continue;
            }
            if (! $this->has($this->middleware($route), ['AdminMiddleware'])) {
                $bad[] = $this->key($route);
            }
        }

        $this->assertSame([], $bad, "admin routes reachable without AdminMiddleware:\n".implode("\n", $bad));
    }

    public function test_allow_list_entries_all_have_a_reason(): void
    {
        foreach (require base_path('tests/Support/route-guard-allowlist.php') as $list) {
            foreach ($list as $key => $reason) {
                $this->assertMatchesRegularExpression('#^(POST|PUT|PATCH|DELETE) \S+$#', $key);
                $this->assertGreaterThan(15, strlen($reason), "allow-list entry {$key} needs a real reason");
            }
        }
    }
}
