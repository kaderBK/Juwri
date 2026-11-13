<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth response headers on EVERY response (global stack).
 *
 * P6-77: only the exact route `publicView` (storefront home/menu) is embeddable; the other
 * `publicView.*` routes (deposit pages, kiosk, unsubscribe) get XFO SAMEORIGIN + CSP
 * `frame-ancestors 'self'`.
 *
 * - ISS-V-C-002: `X-Content-Type-Options: nosniff` — everywhere; stops MIME
 *   sniffing. No framing side effects.
 * - ISS-V-C-003: `X-Frame-Options: SAMEORIGIN` — everywhere EXCEPT the public
 *   storefront (`publicView*` routes), which is intentionally embeddable
 *   cross-origin (agent-settings hands the owner an `<iframe src=".../{slug}">`
 *   for their own site). Enforced anti-clickjacking on login/dashboard/admin/
 *   api without breaking that embed feature.
 *
 * - P6-147: Referrer-Policy, X-XSS-Protection, Permissions-Policy and (HTTPS only) HSTS, the same
 *   values the repo-root .htaccess sets. OpenLiteSpeed ignores `Header` in .htaccess, so the app
 *   sends them itself; on Apache both apply and the values are identical (Apache's `Header set`
 *   simply replaces ours with the same string).
 *
 * All are idempotent — a response that already set the header (a download, or a
 * route that deliberately allows framing) is not overwritten.
 */
class AddSecurityHeaders
{
    /** Same values as section 1 of the repo-root .htaccess (guarded by AppLevelSecurityHeadersTest). */
    public const BASELINE = [
        'X-Content-Type-Options' => 'nosniff',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        // Only features nothing in juwri uses. Web Serial / camera / geolocation / payment stay allowed.
        'Permissions-Policy' => 'accelerometer=(), gyroscope=(), magnetometer=(), midi=(), browsing-topics=()',
    ];

    public const HSTS = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::BASELINE as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }
        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', self::HSTS);
        }

        if ($this->isEmbeddableStorefront($request)) {
            // Embeddable on purpose (owner's own-site iframe): no X-Frame-Options, and any
            // CSP the route set must not pin frame-ancestors to 'self'.
            $this->setFrameAncestors($response, '*', true);
        } else {
            if (! $response->headers->has('X-Frame-Options')) {
                $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            }
            $this->setFrameAncestors($response, "'self'", false);
        }

        return $response;
    }

    /**
     * Route names that may be framed cross-origin. EXPLICIT allowlist: the storefront
     * home/menu only. Every other publicView.* route (deposit payment pages, kiosk,
     * unsubscribe, waitlist, ...) is frame-locked.
     */
    public const EMBEDDABLE_ROUTES = ['publicView'];

    /**
     * @param  bool  $override  true: replace an existing frame-ancestors; false: only add when missing
     */
    private function setFrameAncestors(Response $response, string $value, bool $override): void
    {
        $touched = false;
        foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only'] as $name) {
            $csp = $response->headers->get($name);
            if ($csp === null || $csp === '') {
                continue;
            }
            $touched = true;
            if (preg_match('/(^|;)\s*frame-ancestors\b[^;]*/i', $csp)) {
                if ($override) {
                    $csp = preg_replace('/(^|;)(\s*)frame-ancestors\b[^;]*/i', '$1$2frame-ancestors '.$value, $csp);
                }
            } else {
                $csp = rtrim($csp, "; \t").'; frame-ancestors '.$value;
            }
            $response->headers->set($name, $csp);
        }
        if (! $touched && ! $override) {
            $response->headers->set('Content-Security-Policy', 'frame-ancestors '.$value);
        }
    }

    private function isEmbeddableStorefront(Request $request): bool
    {
        $name = optional($request->route())->getName();

        return is_string($name) && in_array($name, self::EMBEDDABLE_ROUTES, true);
    }
}
