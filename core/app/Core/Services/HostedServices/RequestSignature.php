<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\HostedServices;

/**
 * How a self-hosted installation signs its calls to a juwri-hosted service (owner 2026-10-05, "Protecting the
 * Community limits"): the ONE definition both sides use — the install's {@see HostedServiceClient} signs, the juwri
 * service verifies with the same code. Pure functions (no clock, no container).
 *
 * Headers of every signed request:
 *   X-Juwri-Install    the install identity: a Community install ID issued at registration, or the licence ID
 *   X-Juwri-Timestamp  unix seconds when the request was signed (the service accepts ±{@see WINDOW_SECONDS})
 *   X-Juwri-Nonce      16–64 characters [A-Za-z0-9_-], never reused by an install (the service remembers it)
 *   X-Juwri-Signature  "v1=" + lowercase hex HMAC-SHA256(install secret, canonical string)
 * Context headers (signed too; the service counts what it sees, never trusts them to ALLOW anything):
 *   X-Juwri-Store, X-Juwri-Store-Type, X-Juwri-Branch   the install's own store id / store type / branch id
 *   X-Juwri-Reported-Stores, X-Juwri-Reported-Branches  the install's own counts (recorded to flag mismatches only)
 *
 * Canonical string (lines joined by "\n"):
 *   juwri-hosted-v1, METHOD, path?query (as sent), timestamp, nonce, sha256 hex of the raw body, then the five context
 *   values above in that order ("" when absent).
 *
 * The request never carries an edition: the service resolves it from its own records (licence register / Community).
 */
final class RequestSignature
{
    public const VERSION = 'v1';

    public const WINDOW_SECONDS = 300;

    public const INSTALL = 'X-Juwri-Install';

    public const TIMESTAMP = 'X-Juwri-Timestamp';

    public const NONCE = 'X-Juwri-Nonce';

    public const SIGNATURE = 'X-Juwri-Signature';

    public const STORE = 'X-Juwri-Store';

    public const STORE_TYPE = 'X-Juwri-Store-Type';

    public const BRANCH = 'X-Juwri-Branch';

    public const REPORTED_STORES = 'X-Juwri-Reported-Stores';

    public const REPORTED_BRANCHES = 'X-Juwri-Reported-Branches';

    /** Signed context headers, in canonical order. */
    public const CONTEXT = [self::STORE, self::STORE_TYPE, self::BRANCH, self::REPORTED_STORES, self::REPORTED_BRANCHES];

    public const NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/D';

    /** Install IDs, licence IDs, store / branch ids and store types: printable, no separators that could blur lines. */
    public const REF_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D';

    /** @param  array<string, string|null>  $context  header name => value (missing / null = "") */
    public static function canonical(string $method, string $pathAndQuery, string $timestamp, string $nonce, string $body, array $context): string
    {
        $lines = ['juwri-hosted-'.self::VERSION, strtoupper($method), $pathAndQuery, $timestamp, $nonce, hash('sha256', $body)];
        foreach (self::CONTEXT as $header) {
            $lines[] = (string) ($context[$header] ?? '');
        }

        return implode("\n", $lines);
    }

    public static function sign(string $secret, string $canonical): string
    {
        return self::VERSION.'='.hash_hmac('sha256', $canonical, $secret);
    }

    /** Constant-time comparison of a presented signature header with the expected one. */
    public static function matches(string $secret, string $canonical, string $presented): bool
    {
        return hash_equals(self::sign($secret, $canonical), trim($presented));
    }

    /** Is $timestamp (unix seconds as sent) within the replay window around $now? */
    public static function fresh(string $timestamp, int $now, int $window = self::WINDOW_SECONDS): bool
    {
        return preg_match('/^\d{1,12}$/D', $timestamp) === 1 && abs($now - (int) $timestamp) <= $window;
    }

    /**
     * Every header of a signed request.
     *
     * @param  array<string, string|int|null>  $context  context header => value (see {@see CONTEXT})
     * @return array<string, string>
     */
    public static function headers(string $installId, string $secret, string $method, string $pathAndQuery, string $body, array $context, int $now, string $nonce): array
    {
        $context = array_map(fn ($v) => $v === null ? null : (string) $v, array_intersect_key($context, array_flip(self::CONTEXT)));
        $timestamp = (string) $now;
        $headers = [
            self::INSTALL => $installId,
            self::TIMESTAMP => $timestamp,
            self::NONCE => $nonce,
            self::SIGNATURE => self::sign($secret, self::canonical($method, $pathAndQuery, $timestamp, $nonce, $body, $context)),
        ];
        foreach ($context as $header => $value) {
            if ($value !== null && $value !== '') {
                $headers[$header] = $value;
            }
        }

        return $headers;
    }

    /** A fresh random nonce (32 characters). */
    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
