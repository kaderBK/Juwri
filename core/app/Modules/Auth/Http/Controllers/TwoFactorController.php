<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\LegalAcceptance;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Auth\Events\CredentialsChanged;
use Juwri\Modules\Auth\Services\TwoFactorService;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * H8 — TOTP two-factor auth for owner/admin web accounts. Optional to enroll;
 * once confirmed, login requires a code (or recovery code). A signed "remember this
 * device" cookie can skip the challenge for a bounded window.
 */
class TwoFactorController extends Controller
{
    /** Days a remembered device may skip the 2FA challenge. */
    private const REMEMBER_DAYS = 30;

    /**
     * WI-15-sec: wrong codes allowed per ACCOUNT (any IP) before the pending
     * sign-in is dropped and the account's challenge is locked for the window.
     */
    private const MAX_CHALLENGE_FAILURES = 5;

    /** How long an account's wrong challenge codes are counted, in seconds. */
    private const CHALLENGE_WINDOW = 900;

    public function __construct(private TwoFactorService $twoFactor) {}

    /** Security settings page: enrollment state + QR when enrolling. */
    public function show(Request $request)
    {
        $user = $request->user();
        $data = ['user' => $user, 'enabled' => $user->hasTwoFactorEnabled(), 'legalAcceptances' => $this->legalAcceptances($user)];

        // Mid-enrollment (secret set but not yet confirmed) → show the QR to scan.
        if ($user->two_factor_secret && is_null($user->two_factor_confirmed_at)) {
            $issuer = (string) config('settings.site_title', config('app.name', 'App'));
            $data['qrSvg'] = $this->twoFactor->qrCodeSvg($issuer, (string) $user->email, $user->two_factor_secret);
            $data['secret'] = $user->two_factor_secret;
            $data['pendingConfirm'] = true;
        }

        return view('owner.settings.security', $data);
    }

    /**
     * P1-23: the signed-in owner's OWN legal acceptances (never another user's: the query is pinned to their id).
     *
     * @return Collection<int, LegalAcceptance>
     */
    private function legalAcceptances(User $user): Collection
    {
        return TenantContext::bypass(fn () => LegalAcceptance::query()
            ->where('user_id', (int) $user->getKey())
            ->orderByDesc('accepted_at')->orderByDesc('id')->get());
    }

    /** Begin enrollment: mint a secret + recovery codes (unconfirmed). */
    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => $this->twoFactor->generateSecret(),
            'two_factor_recovery_codes' => $this->twoFactor->generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();

        return back()->with('success', ___('Scan the QR code with your authenticator app, then enter a code to confirm.'));
    }

    /** Confirm enrollment by verifying the first code. */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'required|string']);
        $user = $request->user();

        if (is_null($user->two_factor_secret) || ! $this->twoFactor->verifyCode($user, $request->input('code'))) {
            return back()->withErrors(['code' => ___('That code is invalid. Try again.')]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return back()->with('success', ___('Two-factor authentication is now enabled. Save your recovery codes.'));
    }

    /** Show recovery codes (must be enrolled). */
    public function recoveryCodes(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactorEnabled(), 404);

        return response()->json(['recovery_codes' => $user->two_factor_recovery_codes ?: []]);
    }

    /** Regenerate recovery codes. */
    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactorEnabled(), 404);

        $user->forceFill(['two_factor_recovery_codes' => $this->twoFactor->generateRecoveryCodes()])->save();

        return back()->with('success', ___('New recovery codes generated.'));
    }

    /** Disable 2FA (requires the current password to prevent a hijacked session doing it). */
    public function disable(Request $request): RedirectResponse
    {
        // S8: re-auth is now enforced by the `password.confirm` middleware on the
        // route, so the manual password check here is redundant.
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        // Drop this browser's remember-device cookie so a stale one can't linger.
        Cookie::queue(Cookie::forget('2fa_remember'));

        // P6-75: owner-app devices signed in under 2FA are signed out too.
        event(new CredentialsChanged($user, CredentialsChanged::TWO_FACTOR_DISABLED));

        return back()->with('success', ___('Two-factor authentication disabled.'));
    }

    // ── Login challenge ────────────────────────────────────────────────────────

    /** The challenge screen shown after a correct password when 2FA is on. */
    public function challenge(Request $request)
    {
        if (! $request->session()->has('login.2fa.id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    /**
     * Every sign-in path that has proven the first factor (password, reset
     * link, social identity) calls this before signing the account in. With
     * 2FA on and this device not remembered, the half-finished sign-in is
     * parked in the session (id + a key bound to id|email) and the visitor is
     * sent to the challenge; any web sign-in already made is undone. Null when
     * no challenge is due.
     */
    public static function divertToChallenge(Request $request, User $user, bool $remember = false): JsonResponse|RedirectResponse|null
    {
        if (! $user->hasTwoFactorEnabled() || self::deviceIsRemembered($request, $user)) {
            return null;
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        $request->session()->put('login.2fa.id', $user->id);
        $request->session()->put('login.2fa.key', hash('sha256', $user->id.'|'.$user->email));
        $request->session()->put('login.2fa.remember', $remember);

        $challenge = route('2fa.challenge');

        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => true, 'redirect' => $challenge])
            : redirect()->to($challenge);
    }

    /**
     * Verify the challenge (TOTP or recovery code), then complete login. On top
     * of the per-IP route limiter, wrong codes are counted per account: after
     * MAX_CHALLENGE_FAILURES the pending sign-in is dropped and the account's
     * challenge stays locked for CHALLENGE_WINDOW, whatever the IP.
     */
    public function verifyChallenge(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'required|string']);

        $userId = $request->session()->get('login.2fa.id');
        $user = $userId ? User::find($userId) : null;
        // Corroborate the pending user against the key set at password-verification
        // time, so a tampered session id can't redirect the challenge to another user.
        $expectedKey = $user ? hash('sha256', $user->id.'|'.$user->email) : null;
        if (! $user || ! hash_equals((string) $expectedKey, (string) $request->session()->get('login.2fa.key'))) {
            $request->session()->forget(['login.2fa.id', 'login.2fa.key', 'login.2fa.remember']);

            return redirect()->route('login');
        }

        $failures = 'login.2fa|'.$user->id;
        if (RateLimiter::tooManyAttempts($failures, self::MAX_CHALLENGE_FAILURES)) {
            $request->session()->forget(['login.2fa.id', 'login.2fa.key', 'login.2fa.remember']);
            $seconds = RateLimiter::availableIn($failures);

            return redirect()->route('login')->withErrors([
                'username' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
            ]);
        }

        $ok = $this->twoFactor->verifyCode($user, $request->input('code'))
            || $this->twoFactor->useRecoveryCode($user, $request->input('code'));

        if (! $ok) {
            RateLimiter::hit($failures, self::CHALLENGE_WINDOW);

            return back()->withErrors(['code' => ___('That code is invalid.')]);
        }

        RateLimiter::clear($failures);
        $remember = (bool) $request->session()->pull('login.2fa.remember', false);
        $request->session()->forget(['login.2fa.id', 'login.2fa.key']);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        // Keep the locale prefix chosen at login; a bare dashboard URL would
        // resolve to the prefix-less default locale and flip the language.
        // R4b: a reseller customer on the platform host continues on their reseller's host (same hand-off as the
        // password-only sign-in; answered by the Reseller module, none in Community).
        $handoff = Hooks::first('auth.login-handoff', $request, $user);
        $response = is_string($handoff)
            ? redirect()->away($handoff)
            : redirect()->intended(LoginController::landingFor($user) ?? LaravelLocalization::localizeUrl('dashboard')); // R5: partner portal / chooser

        // Optional: remember THIS device so it can skip the challenge for a while.
        if ($request->boolean('remember_device')) {
            $response->withCookie(
                Cookie::make('2fa_remember', $this->deviceToken($user), self::REMEMBER_DAYS * 24 * 60)
            );
        }

        return $response;
    }

    /** A signed device token bound to the user's current secret (rotates on re-enroll). */
    public static function deviceToken(User $user): string
    {
        return Crypt::encryptString($user->id.'|'.sha1((string) $user->two_factor_secret));
    }

    /** True when the request carries a valid remember-device cookie for the user. */
    public static function deviceIsRemembered(Request $request, User $user): bool
    {
        $cookie = $request->cookie('2fa_remember');
        if (! $cookie) {
            return false;
        }
        try {
            $decoded = Crypt::decryptString($cookie);
        } catch (\Throwable $e) {
            return false;
        }

        return $decoded === $user->id.'|'.sha1((string) $user->two_factor_secret);
    }
}
