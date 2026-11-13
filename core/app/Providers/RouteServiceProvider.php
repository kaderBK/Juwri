<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Providers;

use App\Support\ApiError;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\EnsureLiveUpdatesEnabled;
use Juwri\Core\Services\Tenancy\TenantRateLimits;
use Juwri\Core\Support\RequestEpoch;

/**
 * Loads routes/web.php (web middleware) and routes/api.php (/api prefix, api
 * middleware) with controller names resolved under App\Http\Controllers, and
 * defines the named rate limiters used across the route files.
 */
class RouteServiceProvider extends ServiceProvider
{
    /** Route files name controllers relative to this namespace ('User\\StoreController@show'). */
    protected $namespace = 'App\Http\Controllers';

    /** Where an owner lands after signing in. */
    public const USER = 'dashboard';

    public function boot(): void
    {
        $this->redirectFrontControllerPaths();
        $this->configureRateLimiting();
        $this->registerLiveChannels();

        $this->routes(function () {
            // Container / load-balancer health probe: no session, no cookies, no database (Phase 2 item 16).
            Route::get('/up', fn () => response()->json(['status' => 'up'])->header('Cache-Control', 'no-store'))->name('health.up');
            Route::namespace($this->namespace)->middleware('web')->group(base_path('routes/web.php'));
            // Live updates (Reverb): POST /broadcasting/auth signs a private channel subscription. 404 while off;
            // a login on the web or the staff guard is required (staff-first order, like the POS routes: the staff session fence only guards web-first routes); the tenant middleware opens the TenantContext.
            // P5-19 G2: throttled per login (the 'live-auth' limiter below).
            Broadcast::routes(['middleware' => ['web', EnsureLiveUpdatesEnabled::class, 'auth:staff,web', 'throttle:live-auth']]);
            Route::namespace($this->namespace)->middleware('api')->prefix('api')->group(base_path('routes/api.php'));
            $this->normalizeAbsoluteControllerActions();
        });
    }

    /** Private per-store channels for the orders / KDS / POS screens (routes/channels.php). */
    private function registerLiveChannels(): void
    {
        require base_path('routes/channels.php');
    }

    /**
     * P1-08: a controller that moved out of App\Http\Controllers is named with a leading
     * backslash in the route files ('\Juwri\Core\Http\Controllers\X@show') so the group
     * namespace above is not prefixed. Once registered, drop that backslash so getActionName()
     * / getControllerClass() give the plain class name, as for every other route (cached routes
     * keep the normalized value).
     */
    private function normalizeAbsoluteControllerActions(): void
    {
        $routes = Route::getRoutes();
        foreach ($routes->getRoutes() as $route) {
            $action = $route->getAction();
            if (is_string($action['uses'] ?? null) && str_starts_with($action['uses'], '\\')) {
                $action['uses'] = ltrim($action['uses'], '\\');
                if (is_string($action['controller'] ?? null)) {
                    $action['controller'] = ltrim($action['controller'], '\\');
                }
                $route->setAction($action);
            }
        }
        $routes->refreshActionLookups();
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('api.ip_rate', 60))->by($request->user()?->id ?: $request->ip());
        });

        // GAP-5: PER-TOKEN limit for the public Storefront API, so each token gets its own budget instead of sharing
        // one per-IP bucket. P3-06b fix: the token is resolved here from the raw header (TenantRateLimits) — Laravel's
        // middleware priority runs ThrottleRequests BEFORE StorefrontTokenAuth, so the bound token was never there
        // (every request fell back to per IP; a long-lived worker could key on the previous request's token).
        RateLimiter::for('storefront', function (Request $request) {
            $max = (int) config('api.storefront_rate', 120);
            $token = TenantRateLimits::storefrontToken(TenantRateLimits::rawStorefrontToken($request));
            $key = $token ? 'sft:'.$token['id'] : 'sft-ip:'.$request->ip();

            return Limit::perMinute($max)->by($key);
        });

        // P3-06b (kit/api README §2.9): `storefront-write` for the headless storefront API's writes (quote, orders,
        // customer OTP): per token, plus per token + IP for a browser token (one shopper cannot spend the whole
        // site's budget), plus one ceiling per ACCOUNT across all of its stores' tokens (TenantRateLimits idea).
        // Unknown token → per IP (StorefrontTokenAuth then answers 401).
        RateLimiter::for('storefront-write', function (Request $request) {
            $max = (int) config('api.storefront_write_rate', 30);
            $token = TenantRateLimits::storefrontToken(TenantRateLimits::rawStorefrontToken($request));
            if ($token === null) {
                return Limit::perMinute($max)->by('sfw-ip:'.$request->ip());
            }
            $limits = [Limit::perMinute($max)->by('sfw:'.$token['id'])];
            if ($token['browser']) {
                $limits[] = Limit::perMinute($max)->by('sfw-ip:'.$token['id'].':'.$request->ip());
            }
            if ($token['account'] > 0) {
                $limits[] = Limit::perMinute((int) config('tenancy.rate_limits.storefront_write_per_account', 300))->by('sfw-acct:'.$token['account']);
            }

            return $limits;
        });

        // A1: PER-TOKEN limit for the owner token API. The group throttle runs BEFORE
        // ApiTokenAuth (owner API applies auth per-route), so keying on the bound user
        // would always see nothing and fall back to IP — every token behind one office
        // NAT then shares a single bucket. Key on a hash of the raw bearer/query token
        // instead: 1:1 with the ApiToken row, no DB hit, order-independent.
        RateLimiter::for('owner-api', function (Request $request) {
            $max = (int) config('api.owner_rate', 120);
            $raw = $request->bearerToken() ?? $request->query('api_token');
            $key = $raw ? 'oapi:'.hash('sha256', $raw) : 'oapi-ip:'.$request->ip();
            // P1-04 slice 7: plus one budget per ACCOUNT across all its tokens (per-tenant limit).
            $account = $raw ? TenantRateLimits::ownerTokenAccount($raw) : null;

            return $account === null ? Limit::perMinute($max)->by($key) : [
                Limit::perMinute($max)->by($key),
                Limit::perMinute((int) config('tenancy.rate_limits.owner_api_per_account', 600))->by('oapi-acct:'.$account),
            ];
        });

        // A1: same per-token keying for the reseller API (ResellerApiAuth is group
        // middleware, but bearer-hash keying works uniformly and needs no bound state).
        RateLimiter::for('reseller-api', function (Request $request) {
            $max = (int) config('api.reseller_rate', 60);
            $raw = $request->bearerToken() ?? $request->query('api_token');
            $key = $raw ? 'rapi:'.hash('sha256', $raw) : 'rapi-ip:'.$request->ip();

            return Limit::perMinute($max)->by($key);
        });

        // studio-templates P2-5: inline throttle:N,1 keys guests by domain|ip ONLY,
        // so every studio route shared ONE bucket — preview traffic starved the
        // export limit into 429s. Own key per route family fixes it.
        // P6-101: per-user (guests: per-IP) budget for the heavy LIKE search endpoints.
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)
            ->by('search:'.($request->user()?->getAuthIdentifier() ?? 'ip-'.$request->ip())));

        RateLimiter::for('studio-export', function (Request $request) {
            return Limit::perMinute(10)->by('studio-export:'.$request->ip());
        });

        RateLimiter::for('studio-fit', function (Request $request) {
            return Limit::perMinute(10)->by('studio-fit:'.$request->ip());
        });

        // P6-34b: owner-app in-app billing (web session, signed-in owner). Account-token issuance is tightest and keyed
        // per user+IP plus a per-user ceiling; overview is a read the billing screen polls; the two purchase-verify
        // routes share one per-user bucket. All answer 429 in the JSON error envelope (code + Retry-After).
        $billing429 = fn (Request $request, array $headers) => ApiError::response(
            429, 'too_many_requests', 'Too many requests. Try again shortly.', null, null,
            array_map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v, $headers)
        );
        $billingUser = fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? 'ip:'.$request->ip());
        RateLimiter::for('owner-app-billing-token', fn (Request $request) => [
            Limit::perMinute(10)->by('oab-token:'.$billingUser($request).'|'.$request->ip())->response($billing429),
            Limit::perMinute(30)->by('oab-token-user:'.$billingUser($request))->response($billing429),
        ]);
        RateLimiter::for('owner-app-billing-overview', fn (Request $request) => Limit::perMinute(60)->by('oab-overview:'.$billingUser($request))->response($billing429));
        RateLimiter::for('owner-app-billing-verify', fn (Request $request) => Limit::perMinute(20)->by('oab-verify:'.$billingUser($request))->response($billing429));

        RateLimiter::for('auth-login', function (Request $request) {
            $key = Str::lower(trim((string) $request->input('username', $request->input('email', ''))));

            return Limit::perMinute(6)->by(($key ?: $request->ip()).'|'.$request->ip());
        });

        // P1-19: passkey sign-in / confirmation endpoints (options + verify), per IP. Failed assertions
        // also count toward PasskeyController's own per-IP lockout.
        RateLimiter::for('auth-passkey', function (Request $request) {
            return Limit::perMinute(20)->by('passkey|'.$request->ip());
        });

        RateLimiter::for('auth-register', function (Request $request) {
            $key = Str::lower(trim((string) $request->input('email', $request->input('username', ''))));

            return Limit::perMinute(3)->by(($key ?: $request->ip()).'|'.$request->ip());
        });

        RateLimiter::for('auth-password-email', function (Request $request) {
            $key = Str::lower(trim((string) $request->input('email', '')));

            return Limit::perMinute(5)->by(($key ?: $request->ip()).'|'.$request->ip());
        });

        RateLimiter::for('auth-password-reset', function (Request $request) {
            $key = Str::lower(trim((string) $request->input('email', '')));

            return Limit::perMinute(5)->by(($key ?: $request->ip()).'|'.$request->ip());
        });

        RateLimiter::for('auth-verification', function (Request $request) {
            $userId = $request->user()?->id;

            return Limit::perMinute(6)->by(($userId ?: $request->ip()).'|'.$request->ip());
        });

        RateLimiter::for('public-form', function (Request $request) {
            return Limit::perMinute(12)->by($request->ip());
        });

        // Browser CSP reports get their OWN bucket. A numeric `throttle:60,1` keys on domain|ip with no route in
        // it, so every such route shares one counter per visitor: a storefront page whose inline blocks all
        // trip a report-only policy sends ~60 reports on load and that alone used up the visitor's budget for
        // geo/autocomplete, coupon checks and every other `throttle:N,1` call (they answered 429).
        RateLimiter::for('csp-report', function (Request $request) {
            return Limit::perMinute(120)->by('csp-report:'.($request->ip() ?: 'noip'));
        });

        // P5-19 G2: POST /broadcasting/auth. A screen signs one channel per (re)connect and the socket client gives up
        // after 8 failed connects, so 120/min is far above normal use. Own bucket (a numeric throttle:N,1 would share
        // one per visitor with every other such route), keyed on the signed-in staff member or owner (the route needs
        // a login; the class keeps a staff id apart from an owner id).
        RateLimiter::for('live-auth', function (Request $request) {
            $user = $request->user();
            $key = $user ? class_basename($user).':'.$user->getAuthIdentifier() : 'ip:'.($request->ip() ?: 'noip');

            return Limit::perMinute(120)->by('live-auth:'.$key);
        });

        RateLimiter::for('public-check', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // The public order-track GET is POLLED by every storefront theme: two
        // independent client pollers (5s status + 15s prep-ETA) = ~16 req/min per
        // open tracking tab, and a diner may keep it open in more than one tab, so
        // public-check's 30/min is far too tight here. Key primarily on the track
        // token so each tracked order gets its own generous budget (legit polling
        // never starves, even when several diners share one venue-wifi IP), with a
        // per-IP backstop that is what actually closes the abuse surface: a token
        // enumerator mints a fresh token per request, so the token bucket alone
        // would never fire — the IP ceiling stops the scan.
        RateLimiter::for('public-poll', function (Request $request) {
            $token = (string) $request->route('token');
            $ip = $request->ip() ?: 'noip';

            return [
                Limit::perMinute(60)->by($token !== '' ? 'poll-tok:'.$token : 'poll-ip:'.$ip),
                Limit::perMinute(120)->by('poll-ip:'.$ip),
            ];
        });

        RateLimiter::for('newsletter', function (Request $request) {
            $key = Str::lower(trim((string) $request->input('email', '')));

            return Limit::perMinute(8)->by(($key ?: $request->ip()).'|'.$request->ip());
        });

        RateLimiter::for('restaurant-order', function (Request $request) {
            $store = $request->route('store');
            $restaurantKey = is_object($store) && isset($store->id) ? $store->id : (string) $store;

            return Limit::perMinute(10)->by(($restaurantKey ?: 'restaurant').'|'.$request->ip());
        });

        RateLimiter::for('restaurant-waiter', function (Request $request) {
            $store = $request->route('store');
            $restaurantKey = is_object($store) && isset($store->id) ? $store->id : (string) $store;

            return Limit::perMinute(6)->by(($restaurantKey ?: 'restaurant').'|'.$request->ip());
        });

        // ZERO-TOUCH Track B2: storefront voice-input STT (agent.stt). Two
        // dimensions: per browser session (a diner dictating an order sends at
        // most a few clips a minute) AND a per-IP ceiling — sessions are free
        // to mint, so the IP backstop is what actually stops a cookie-rotating
        // abuser, while staying venue-wifi friendly.
        RateLimiter::for('agent-stt', function (Request $request) {
            $session = $request->hasSession() ? $request->session()->getId() : null;
            $ip = $request->ip() ?: 'noip';

            return [
                Limit::perMinute(10)->by(($session ?: $ip).'|stt'),
                Limit::perMinute(30)->by($ip.'|stt-ip'),
            ];
        });
    }

    /**
     * `/index.php/<path>` is answered with a 301 to `/<path>` before routing. The
     * target is always same-site: leading slashes collapse to one, so
     * `/index.php//evil.test` cannot become a protocol-relative redirect.
     */
    protected function redirectFrontControllerPaths(): void
    {
        // A long-lived worker boots once with no request of its own (and exit() would end it): the Docker image's
        // Caddyfile does this redirect for it.
        if (RequestEpoch::inWorker()) {
            return;
        }

        $uri = request()->getRequestUri();
        if (! str_contains($uri, '/index.php/')) {
            return;
        }

        header('Location: /'.ltrim(str_replace('index.php/', '', $uri), '/'), true, 301);
        exit;
    }
}
