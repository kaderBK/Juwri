<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * Scheme-restricted URL guard for reseller-page block output.
 *
 * FILTER_VALIDATE_URL alone accepts dangerous schemes such as
 * `javascript://comment%0aalert(1)`, which — emitted into an href — yields
 * stored XSS on the reseller's public brand domain. This clamps any URL
 * destined for an href/src/background to the http(s) schemes only.
 */
class SafeUrl
{
    /**
     * Return the URL only if it is a well-formed http/https URL, else null.
     */
    public static function web(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /**
     * Owner-typed link destined for a public href: absolute http(s), mailto,
     * tel, sms, whatsapp, a site-relative path ("/x", "#x", "?x"), else null.
     * Rejects javascript:/data:/vbscript: and protocol-relative "//host" after
     * stripping the control characters browsers ignore inside a scheme.
     */
    public static function link(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        $probe = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $url));
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $m) === 1) {
            return in_array($m[1], ['http', 'https', 'mailto', 'tel', 'sms', 'whatsapp'], true) ? $url : null;
        }
        if (str_starts_with($probe, '//') || str_starts_with($probe, '\\')) {
            return null;
        }

        return $url;
    }
}
