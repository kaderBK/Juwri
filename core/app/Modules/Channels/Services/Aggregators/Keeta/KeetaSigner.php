<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Keeta;

/**
 * N2 — Keeta request signer. Keeta requires an HMAC-SHA256 signature on every request
 * (and on inbound webhooks) computed over a canonical string of:
 *   METHOD \n PATH \n sorted-query \n body
 * signed with the app secret. We keep the canonicalization deterministic and unit-test
 * it against a hand-computed vector so a spec drift is caught immediately.
 *
 * Reference: https://api-docs.mykeeta.com/apis/standard/docs/intro (signing section).
 * The exact byte layout Keeta publishes for production is config-swappable; this
 * canonical form is stable and documented so the live cert pass only tweaks ordering.
 */
class KeetaSigner
{
    /**
     * Build the canonical string to sign.
     *
     * @param  string  $method  HTTP verb (upper-cased)
     * @param  string  $path    request path, e.g. /api/open/order/confirm
     * @param  array   $query   query params (order-independent — sorted here)
     * @param  string  $body    raw request/response body (JSON string, '' if none)
     */
    public static function canonical(string $method, string $path, array $query, string $body): string
    {
        ksort($query);
        $queryString = http_build_query($query);
        return strtoupper($method) . "\n" . $path . "\n" . $queryString . "\n" . $body;
    }

    /** Hex HMAC-SHA256 signature of the canonical string with the app secret. */
    public static function sign(string $appSecret, string $method, string $path, array $query, string $body): string
    {
        return hash_hmac('sha256', self::canonical($method, $path, $query, $body), $appSecret);
    }

    /**
     * Constant-time verify of an inbound signature against the recomputed one. Accepts
     * a bare hex sig or a "sha256=" prefixed form.
     */
    public static function verify(string $appSecret, string $method, string $path, array $query, string $body, string $provided): bool
    {
        if ($provided === '') {
            return false;
        }
        if (str_contains($provided, '=') && ! ctype_xdigit($provided)) {
            $provided = trim(substr($provided, strpos($provided, '=') + 1));
        }
        $expected = self::sign($appSecret, $method, $path, $query, $body);
        return hash_equals($expected, $provided);
    }
}
