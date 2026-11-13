<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Legal;

use Illuminate\Http\Request;

/**
 * P1-23: the visitor's cookie choice (banner `partials/cookie-consent`). Strictly necessary cookies only until the
 * visitor chooses; analytics and marketing scripts are rendered inert (`type="text/plain"` +
 * `data-cookie-consent="<category>"`) and the banner script starts them after consent. The choice lives in the
 * plain (unencrypted, see EncryptCookies) cookie `site_consent` = `v1.a<0|1>.m<0|1>` for 6 months, written by the
 * banner script; the server reads it for server-set non-essential cookies (referral attribution). Categories and
 * lifetimes come from the cookie registry ({@see CookieRegistry}), the same list the banner and /cookies read.
 */
final class CookieConsent
{
    public const COOKIE = 'site_consent';

    /** Days the choice is remembered (privacy.md: 6 months). */
    public const DAYS = 182;

    /** @return array{decided: bool, analytics: bool, marketing: bool} */
    public static function fromRequest(?Request $request = null): array
    {
        $request ??= request();
        $value = (string) $request->cookies->get(self::COOKIE, '');
        if (preg_match('/^v1\.a([01])\.m([01])$/', $value, $m) !== 1) {
            return ['decided' => false, 'analytics' => false, 'marketing' => false];
        }

        return ['decided' => true, 'analytics' => $m[1] === '1', 'marketing' => $m[2] === '1'];
    }

    public static function allows(string $category, ?Request $request = null): bool
    {
        return in_array($category, CookieRegistry::optionalCategories(), true) && (self::fromRequest($request)[$category] ?? false);
    }

    /**
     * Keep a non-essential (attribution) value: a cookie with consent to its registry category, for its registry
     * lifetime ({@see CookieRegistry}; an unlisted cookie throws), otherwise only in the session (the visit's own
     * sign-up / order still gets it; nothing lasting is stored on the device).
     */
    public static function rememberAttribution(Request $request, string $name, string $value): void
    {
        $days = CookieRegistry::days($name);
        $category = CookieRegistry::all()[(string) CookieRegistry::forName($name)]['category'];
        if (self::allows($category, $request)) {
            cookie()->queue(cookie($name, $value, 60 * 24 * $days));

            return;
        }
        if ($request->hasSession()) {
            $request->session()->put('attribution.'.$name, $value);
        }
    }

    /** An attribution value from its cookie or, without consent, from the session. */
    public static function attribution(Request $request, string $name): ?string
    {
        $value = $request->cookie($name);
        if (! is_string($value) || $value === '') {
            $value = $request->hasSession() ? $request->session()->get('attribution.'.$name) : null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function forgetAttribution(Request $request, string $name): void
    {
        cookie()->queue(cookie()->forget($name));
        if ($request->hasSession()) {
            $request->session()->forget('attribution.'.$name);
        }
    }
}
