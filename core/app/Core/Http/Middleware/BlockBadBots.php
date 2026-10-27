<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P6-147: the User-Agent blocklist of the repo-root .htaccess (`SetEnvIfNoCase` + `Require not env
 * spammer`), app-level. It is a cost control (SEO/AI crawlers that re-crawl everything and indexed
 * nothing we use), and OpenLiteSpeed ignores those directives, so the crawlers reached PHP there.
 * First in the global stack: a blocked crawler costs no session, DB or store lookup.
 * Same list and semantics as .htaccess: case-insensitive substring of the User-Agent, answer 403.
 */
class BlockBadBots
{
    /** Keep in sync with section 2 of the repo-root .htaccess (AppLevelSecurityHeadersTest checks). */
    public const USER_AGENTS = [
        'Yandex', 'DTS Agent', 'SemrushBot', 'Pcore', 'MJ12bot', 'AhrefsBot', 'DotBot', 'Screaming',
        'MegaIndex', 'spbot', 'Owlin', 'Flamingo_SearchEngine', 'GPTBot',
        // .htaccess rewrite rule (PROD CPU 2026-09-17); NOT facebookexternalhit (link previews).
        'meta-externalads',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $ua = (string) $request->userAgent();
        if ($ua !== '' && self::isBlocked($ua)) {
            return response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return $next($request);
    }

    public static function isBlocked(string $userAgent): bool
    {
        foreach (self::USER_AGENTS as $needle) {
            if (stripos($userAgent, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
