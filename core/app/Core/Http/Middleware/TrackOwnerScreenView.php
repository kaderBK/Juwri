<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Juwri\Core\Support\OwnerPwa;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner dashboard PWA (PWA-SPEC §3.6): count which dashboard screens owners open,
 * on which device and surface, so the mobile redesign starts with the screens
 * that matter. Written in terminate() — after the response is sent — and never
 * throws. Aggregated per day; no URLs, no query strings.
 */
class TrackOwnerScreenView
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! self::countable($request, $response)) {
                return;
            }

            $route = (string) optional($request->route())->getName();
            $now = now();
            $table = DB::table('owner_screen_views');
            $key = [
                'day' => $now->toDateString(),
                'route_name' => mb_substr($route, 0, 120),
                'surface' => OwnerPwa::surface($request),
                'device' => OwnerPwa::device($request->userAgent()),
                'user_id' => (int) Auth::id(),
            ];

            $updated = $table->where($key)->update(['views' => DB::raw('views + 1'), 'updated_at' => $now]);
            if ($updated === 0) {
                $table->insertOrIgnore($key + ['views' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        } catch (\Throwable) {
            // Analytics must never break or slow a dashboard page.
        }
    }

    public static function countable(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || ! Auth::check() || $response->getStatusCode() !== 200) {
            return false;
        }
        if ($request->expectsJson() || $request->ajax() || $request->headers->has('Turbo-Frame')) {
            return false;
        }
        $purpose = strtolower((string) ($request->headers->get('Purpose') ?? $request->headers->get('Sec-Purpose') ?? $request->headers->get('X-Sec-Purpose') ?? ''));
        if (str_contains($purpose, 'prefetch')) {
            return false;
        }
        $name = optional($request->route())->getName();
        if (! is_string($name) || $name === '') {
            return false;
        }

        return str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html');
    }
}
