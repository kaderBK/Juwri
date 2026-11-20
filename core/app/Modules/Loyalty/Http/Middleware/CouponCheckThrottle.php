<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coupons C5: stop coupon-code guessing (matters once 1,000-code batches exist).
 *
 * Usage: ->middleware('coupon.check:pos')  (the argument is the surface = its own bucket, so a busy storefront
 * cannot starve plan checkout).
 *
 * Unlike Laravel's throttle this only counts FAILED checks: a customer who applies a valid code (or a cashier
 * applying codes all day) never spends the budget. Limits: 10 a minute and 50 an hour, per IP and per signed-in
 * account (staff, owner, diner or shopper guard), per surface. Every limiter key starts with `coupon-check:`.
 *
 * A request only takes part when it carries a coupon field, so a plain checkout or signup POST is untouched.
 *
 * "Failed" is read from the response, because each surface reports it its own way: 4xx (422/404/400), JSON
 * `valid|ok|success: false`, a validation error on a coupon field, or an error flashed by the controller.
 */
class CouponCheckThrottle
{
    public const KEY_PREFIX = 'coupon-check';

    private const FIELDS = ['coupon_code', 'coupon', 'code'];

    private const GUARDS = ['staff', 'web', 'customer', 'shopper'];

    /** @var array<int, array{0: int, 1: int}> [max attempts, decay seconds] */
    private const WINDOWS = [[10, 60], [50, 3600]];

    public function handle(Request $request, Closure $next, string $surface = 'default'): Response
    {
        if (! $this->carriesCoupon($request)) {
            return $next($request);
        }

        $limits = $this->limits($request, $surface);

        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return $this->blocked($request, RateLimiter::availableIn($key));
            }
        }

        $response = $next($request);

        if ($this->failed($request, $response)) {
            foreach ($limits as [$key, , $decay]) {
                RateLimiter::hit($key, $decay);
            }
        }

        return $response;
    }

    private function carriesCoupon(Request $request): bool
    {
        foreach (self::FIELDS as $field) {
            $value = $request->input($field);
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{0: string, 1: int, 2: int}> [key, max attempts, decay seconds] */
    private function limits(Request $request, string $surface): array
    {
        $subjects = ['ip:'.($request->ip() ?: 'noip')];
        foreach (self::GUARDS as $guard) {
            if (config('auth.guards.'.$guard) === null) {
                continue;
            }
            $id = auth()->guard($guard)->id();
            if ($id) {
                $subjects[] = $guard.':'.$id;
                break;
            }
        }

        $out = [];
        foreach ($subjects as $subject) {
            foreach (self::WINDOWS as [$max, $decay]) {
                $out[] = [self::KEY_PREFIX.':'.$surface.':'.$subject.':'.$decay, $max, $decay];
            }
        }

        return $out;
    }

    private function failed(Request $request, Response $response): bool
    {
        $status = $response->getStatusCode();
        if ($status === 429) {
            return false;
        }
        if (in_array($status, [400, 404, 422], true)) {
            return true;
        }

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            return is_array($data)
                && (($data['valid'] ?? null) === false || ($data['ok'] ?? null) === false || ($data['success'] ?? null) === false);
        }

        if ($request->hasSession()) {
            $session = $request->session();
            // Only what THIS request flashed (not leftovers from the previous page).
            $flashed = (array) $session->get('_flash.new', []);
            if (in_array('errors', $flashed, true)) {
                $errors = $session->get('errors');
                if ($errors instanceof ViewErrorBag) {
                    foreach (self::FIELDS as $field) {
                        if ($errors->has($field)) {
                            return true;
                        }
                    }
                }
            }
            if (in_array('error', $flashed, true) && filled($session->get('error'))) {
                return true;
            }
            // app_alert_error() (the toast every owner/billing screen flashes on a refused code).
            if (in_array('app_alert_type', $flashed, true) && $session->get('app_alert_type') === 'error') {
                return true;
            }
        }

        return false;
    }

    private function blocked(Request $request, int $retryAfter): Response
    {
        $message = ___('Too many coupon attempts. Please try again later.');
        $headers = ['Retry-After' => (string) $retryAfter];

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'valid' => false, 'success' => false, 'error' => $message, 'message' => $message], 429, $headers);
        }

        abort(429, $message, $headers);
    }
}
