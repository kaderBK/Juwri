<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * Validates a caller-supplied post-auth `?redirect` target before it is trusted
 * as an intended-URL. This is the open-redirect guard for the auth flow: a raw
 * `?redirect` is attacker-controllable (it rides in a link), so we only ever
 * honour a target that stays on THIS site.
 *
 * Allowed:
 *   - a relative path beginning with a single "/" (e.g. "/en/studio/app")
 *   - an absolute http(s) URL whose host matches the app host or the request host
 * Rejected: scheme-relative "//evil.com", other-host URLs, non-http(s) schemes
 * (javascript:, data:, mailto:…), control characters, and anything unparseable.
 */
class SafeRedirect
{
    /** @return string|null the validated same-site target, or null to fall back to the default */
    public static function resolve(?string $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 2048) {
            return null;
        }
        // No control chars (defends against header/redirect smuggling).
        if (preg_match('/[\x00-\x1f\x7f]/', $raw)) {
            return null;
        }
        // Scheme-relative ("//host", "/\\host", "\\host") is a cross-origin escape, not a path.
        // Browsers treat "\" as "/", so a backslash anywhere in the leading path is refused, as is
        // any percent-encoded form (%5C, %2F) that decodes into one.
        if (preg_match('#^[/\\\\]{2}#', $raw) || str_starts_with($raw, '\\')) {
            return null;
        }
        if (str_starts_with($raw, '/')) {
            $decoded = rawurldecode(rawurldecode($raw));
            if (preg_match('#^[/\\\\]{2}#', $decoded) || preg_match('/[\x00-\x1f\x7f]/', $decoded) || str_contains(strtok($decoded, '?#') ?: '', '\\')) {
                return null;
            }
        }
        // Relative same-site path.
        if (str_starts_with($raw, '/')) {
            return $raw;
        }

        // Browsers read "\\" as "/" and stop the authority there: "https://evil.com\\@app.host" is evil.com to them
        // but app.host to parse_url. Refuse any backslash or userinfo in an absolute target.
        if (str_contains($raw, '\\') || preg_match('/%5c/i', $raw)) {
            return null;
        }
        $parts = parse_url($raw);
        if ($parts === false || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null; // unparseable, or a bare/scheme-only value (e.g. "javascript:…")
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $reqHost = strtolower((string) request()->getHost());

        return ($host === $appHost || $host === $reqHost) ? $raw : null;
    }

    /**
     * Same as resolve() but only ever a local path ("/..."): an absolute same-host URL is
     * reduced to its path+query, so callers that must stay in-app get a relative target.
     * THE helper for every redirect_to / return_to / next / intended input.
     */
    public static function path(?string $raw, ?string $default = null): ?string
    {
        $target = self::resolve($raw);
        if ($target === null) {
            return $default;
        }
        if (str_starts_with($target, '/')) {
            return $target;
        }
        $parts = parse_url($target);
        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        $path .= isset($parts['query']) ? '?'.$parts['query'] : '';

        return self::resolve($path) ?? $default;
    }

    /**
     * If $raw resolves to a safe same-site target, stash it as the session
     * intended-URL so the standard `redirect()->intended()` path honours it
     * after authentication. No-op (and never overwrites an existing intended
     * URL with junk) when the target is missing or unsafe.
     */
    public static function stashIntended(?string $raw): void
    {
        $target = self::resolve($raw);
        if ($target !== null) {
            // Same key the standard redirect()->intended() reads/pulls.
            session()->put('url.intended', $target);
        }
    }
}
