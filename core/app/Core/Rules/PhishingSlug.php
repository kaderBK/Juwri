<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A4 (upstream b80061178): a store link lives on the platform's
 * own domain (platform-host/{slug}), so a slug like "secure-login" or
 * "account-verify" makes a convincing phishing page. New or changed slugs
 * containing sign-in / account / password words are refused.
 *
 * Post::saving only swaps the auto-generated slug of a NEW store; an existing
 * store whose slug already contains one of these words is never renamed (its
 * QR codes and shared links keep working). Pass the store's current slug to
 * allow saving it unchanged.
 */
class PhishingSlug implements ValidationRule
{
    /** Single words and two-word joins (sign-in → signin) that read as a login or account page. */
    private const WORDS = [
        'login', 'logon', 'signin', 'signon', 'auth', 'oauth', 'sso', 'account', 'accounts', 'myaccount',
        'secure', 'verify', 'verification', 'password', 'passwd', 'reset', 'recover', 'recovery',
        '2fa', 'otp', 'admin', 'administrator', 'superadmin', 'webmail', 'juwri', 'billing', 'wallet',
    ];

    public function __construct(private ?string $current = null) {}

    public static function looksLikePhishing(?string $slug): bool
    {
        $tokens = array_values(array_filter(preg_split('/[-_.]+/', mb_strtolower((string) $slug)) ?: []));
        $candidates = $tokens;
        for ($i = 0; $i + 1 < count($tokens); $i++) {
            $candidates[] = $tokens[$i].$tokens[$i + 1];
        }

        return array_intersect($candidates, self::WORDS) !== [];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || ($this->current !== null && mb_strtolower($value) === mb_strtolower($this->current))) {
            return;
        }
        if (self::looksLikePhishing($value)) {
            $fail(___('This link name looks like a sign-in or account page, please choose another one.'));
        }
    }
}
