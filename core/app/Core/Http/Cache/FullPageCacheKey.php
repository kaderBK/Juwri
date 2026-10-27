<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Cache;

use Illuminate\Http\Request;

/**
 * P6-79: the request inputs shared by the storefront and marketing full-page caches.
 *
 * A cached page is rendered once and served to every guest under its key, so every input that changes the
 * rendered HTML must either be in the key or be removed before the page renders:
 *  - origin: scheme + host + non-default port. Absolute URLs (canonical, og:url, hreflang, assets, manifest)
 *    come from the request's scheme/host/port, which a trusted proxy's X-Forwarded-Proto/-Port can change —
 *    an http:// or :port render must never be served on the clean https hit.
 *  - tracking tags (utm_*, fbclid, gclid): they do not change the page, but `fullUrlWithQuery()` links copy
 *    them. They are stripped from the request before render instead of being keyed (keeps the hit rate, and
 *    one visitor's tags never ship in every guest's links).
 */
final class FullPageCacheKey
{
    /** @var list<string> */
    public const TRACKING_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'];

    /** Scheme, host and (non-default) port the page's absolute URLs are built from. */
    public static function origin(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }

    /** Remove the tracking tags from the request (query bag AND query string) so the render never echoes them. */
    public static function stripTrackingParams(Request $request): void
    {
        $query = $request->query->all();
        $clean = array_diff_key($query, array_flip(self::TRACKING_PARAMS));
        if (count($clean) === count($query)) {
            return;
        }

        $request->query->replace($clean);
        $request->server->set('QUERY_STRING', http_build_query($clean, '', '&', PHP_QUERY_RFC3986));
    }

    /** The path plus the (already normalised) query string — the URI a page is rendered for. */
    public static function uri(Request $request): string
    {
        $qs = $request->getQueryString();

        return $request->getPathInfo().($qs !== null && $qs !== '' ? '?'.$qs : '');
    }
}
