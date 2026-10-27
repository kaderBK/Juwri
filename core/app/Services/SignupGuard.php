<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Fgribreau\MailChecker;
use Juwri\Core\Services\AbuseGuard;

/**
 * Abuse Guard P4 (SPEC §3): signup abuse checks — disposable email, honeypot,
 * per-IP/normalized-email velocity, and Cloudflare Turnstile (seam: skipped while
 * keys are empty). Every check routes through AbuseGuard::hit('signup', …) so WARN
 * logs+allows and ENFORCE blocks with a generic ValidationException on the email.
 *
 * Call assertAllowed() right before creating the account in each public signup.
 */
class SignupGuard
{
    public const GUARD = 'signup';
    public const HONEYPOT = 'company_url'; // hidden field; humans leave it blank

    /**
     * @throws ValidationException in enforce mode when a signup is rejected.
     */
    public static function assertAllowed(Request $request, string $email): void
    {
        if (AbuseGuard::mode(self::GUARD) === AbuseGuard::OFF) {
            return;
        }

        $ctx = ['ip' => $request->ip(), 'meta' => ['email' => $email]];

        // Honeypot — a filled hidden field means a bot.
        if (filled($request->input(self::HONEYPOT))) {
            self::deny($request, 'honeypot', $ctx);
        }

        // Disposable / blacklisted email domain (MailChecker::isValid → false = bad).
        if (! MailChecker::isValid($email)) {
            self::deny($request, 'disposable_email', $ctx, 'Please use a permanent email address.');
        }

        // Turnstile (only when keys are configured — otherwise a no-op seam).
        if (! self::verifyTurnstile($request)) {
            self::deny($request, 'turnstile_failed', $ctx, 'Verification failed. Please try again.');
        }

        // Velocity — same normalized email or IP creating too many accounts/day.
        if (self::velocityExceeded($request, $email)) {
            self::deny($request, 'velocity', $ctx, 'Too many signups. Please try again later.');
        }
    }

    /** Normalize gmail-style plus/dot aliases so x+anything@ and x.y@ collapse. */
    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (! str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = preg_replace('/\+.*$/', '', $local);
        $local = str_replace('.', '', $local);

        return $local . '@' . $domain;
    }

    protected static function velocityExceeded(Request $request, string $email): bool
    {
        $cap = (int) AbuseGuard::value(self::GUARD, 'velocity_per_day', 3);
        if ($cap <= 0) {
            return false;
        }

        $day = now()->format('Ymd');
        $emailKey = 'abuse:signup:vel:e:' . $day . ':' . sha1(self::normalizeEmail($email));
        $ipKey = 'abuse:signup:vel:i:' . $day . ':' . sha1((string) $request->ip());

        $emailN = (int) Cache::get($emailKey, 0);
        $ipN = (int) Cache::get($ipKey, 0);

        // Count this attempt.
        Cache::put($emailKey, $emailN + 1, now()->addDay());
        Cache::put($ipKey, $ipN + 1, now()->addDay());

        return $emailN >= $cap || $ipN >= $cap;
    }

    /**
     * Turnstile verification. Returns true (pass) when no secret key is configured
     * — the widget is skipped entirely until the owner adds Cloudflare keys.
     */
    public static function verifyTurnstile(Request $request): bool
    {
        $secret = (string) config('settings.abuse_turnstile_secret_key', '');
        if ($secret === '') {
            return true; // seam: no keys yet
        }

        $token = $request->input('cf-turnstile-response');
        if (! $token) {
            return false;
        }

        try {
            $resp = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);

            return (bool) ($resp->json('success') ?? false);
        } catch (\Throwable $e) {
            return true; // fail-open on network error — never lock out real users
        }
    }

    public static function turnstileSiteKey(): string
    {
        return (string) config('settings.abuse_turnstile_site_key', '');
    }

    protected static function deny(Request $request, string $action, array $ctx, string $message = 'Signup rejected.'): void
    {
        if (! AbuseGuard::hit(self::GUARD, $action, $ctx)) {
            throw ValidationException::withMessages(['email' => $message]);
        }
    }
}
