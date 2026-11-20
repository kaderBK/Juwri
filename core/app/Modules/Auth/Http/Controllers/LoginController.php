<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\AccountDeletion;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\OwnerApp;
use Juwri\Core\Support\SafeRedirect;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner and admin sign-in (web guard) and sign-out.
 *
 * Sign-in accepts a username or an email address in the `username` field.
 * Protections: per account+IP lockout after MAX_ATTEMPTS failures (on top of
 * the route-level `throttle:auth-login` limiter), a fresh session id on
 * success, optional reCAPTCHA, the blocked-account check and the TOTP
 * challenge for accounts with two-factor auth.
 */
class LoginController extends Controller
{
    /** Failed attempts allowed per account+IP before the lockout starts. */
    private const MAX_ATTEMPTS = 5;

    /** How long failed attempts are remembered, in seconds. */
    private const ATTEMPT_WINDOW = 60;

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    public function showLoginForm(Request $request): View
    {
        // A same-site ?redirect (e.g. the Studio "Save my work" button) becomes
        // the intended URL, so both a direct sign-in and the 2FA challenge
        // return there. Cross-site or malformed targets are ignored.
        SafeRedirect::stashIntended($request->query('redirect'));

        return view('auth.login');
    }

    public function login(Request $request): Response
    {
        $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ] + validate_recaptcha());

        $attemptKey = $this->attemptKey($request);

        if (RateLimiter::tooManyAttempts($attemptKey, self::MAX_ATTEMPTS)) {
            event(new Lockout($request));
            $seconds = RateLimiter::availableIn($attemptKey);

            throw ValidationException::withMessages([
                'username' => [trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)])],
            ])->status(Response::HTTP_TOO_MANY_REQUESTS);
        }

        $guard = Auth::guard();

        if (! $guard->attempt($this->credentials($request), $request->boolean('remember'))) {
            RateLimiter::hit($attemptKey, self::ATTEMPT_WINDOW);

            throw ValidationException::withMessages([
                'username' => [trans('auth.failed')],
            ]);
        }

        // The password was just proven, so password.confirm screens can be
        // skipped for the usual window. New session id against fixation.
        $request->session()->put('auth.password_confirmed_at', time());
        $request->session()->regenerate();
        RateLimiter::clear($attemptKey);

        $user = $guard->user();

        if ($response = $this->afterPasswordCheck($request, $user)) {
            return $response;
        }

        // R4b: a reseller customer signing in on the platform host continues on their reseller's host (sign in there
        // once; sessions are per host). The Reseller module (ee) answers the hook; Community has none.
        if ($user instanceof User && is_string($handoff = Hooks::first('auth.login-handoff', $request, $user))) {
            return redirect()->away($handoff);
        }

        return $request->wantsJson()
            ? new JsonResponse([], 204)
            : redirect()->intended(self::homeFor($user));
    }

    public function logout(Request $request): Response
    {
        $impersonatorId = $request->session()->get('quick_admin_user_id');

        if (is_staff_dashboard()) {
            Auth::guard('staff')->logout();
            $request->session()->forget('staff_session_id');
        }

        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // An admin who was viewing the app as one of its users goes back to
        // their own account instead of being signed out completely.
        // P6-229: only a still-active admin gets the session back; a demoted or disabled one lands on the login page.
        $admin = $impersonatorId ? User::find($impersonatorId) : null;
        if ($admin && $admin->isAdmin() && (int) $admin->status === 1) {
            log_activity('admin.impersonate_exit', $admin, ['admin_id' => $impersonatorId, 'ip' => $request->ip()]);
            Auth::login($admin);

            return redirect(route('admin.users.index'));
        }

        if ($request->wantsJson()) {
            return new JsonResponse([], 204);
        }

        // Inside the owner mobile app a deliberate sign-out lands on the login
        // page with a marker, so the app can tell it apart from an expired
        // session and drop its own token too (see OwnerApp).
        if (OwnerApp::inApp($request)) {
            return redirect(route('login', ['app_logout' => 1]));
        }

        return redirect('/');
    }

    /**
     * The `username` field takes either a username or an email address.
     *
     * @return array{password: mixed}&array<string, mixed>
     */
    private function credentials(Request $request): array
    {
        $login = $request->input('username');
        $column = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return [$column => $login, 'password' => $request->input('password')];
    }

    private function attemptKey(Request $request): string
    {
        return 'login|'.Str::transliterate(Str::lower((string) $request->input('username')).'|'.$request->ip());
    }

    /** Where a signed-in account goes when there is no intended URL. */
    public static function homeFor(?Authenticatable $user): string
    {
        if ($user instanceof User && $user->isAdmin()) {
            return admin_url().'/dashboard';
        }

        if (($landing = self::landingFor($user)) !== null) {
            return $landing;
        }

        // Keep the language prefix the user signed in under: a bare
        // "dashboard" would resolve to the default locale and switch the
        // whole dashboard to another language.
        return LaravelLocalization::localizeUrl(RouteServiceProvider::USER);
    }

    /**
     * R5: the post-sign-in default the Reseller module (ee) picks for a partner owner or team seat: the partner
     * portal, or the portal / stores chooser. Null for everyone else and in Community (no hook). Callers pass it as
     * the DEFAULT of redirect()->intended(), so an intended URL still wins. The answer is an app-internal URL
     * built by the module (never request input).
     */
    public static function landingFor(?Authenticatable $user): ?string
    {
        if (! $user instanceof User || $user->isAdmin()) {
            return null;
        }
        $landing = Hooks::first('auth.login-landing', $user);

        return is_string($landing) && $landing !== '' ? $landing : null;
    }

    /**
     * Checks that run once the password is known to be right. Returns a
     * response when sign-in must not complete here.
     */
    private function afterPasswordCheck(Request $request, ?Authenticatable $user): ?Response
    {
        if ($user instanceof User && ($blocked = self::blockedMessage($user)) !== null) {
            Auth::logout();
            app_alert_error($blocked);

            return redirect()->route('login');
        }

        if (! $user instanceof User) {
            return null;
        }

        self::adoptVisitorLanguage($request, $user);

        // Two-factor accounts on a device that is not remembered: park the
        // half-finished sign-in in the session and ask for the TOTP code.
        return TwoFactorController::divertToChallenge($request, $user, $request->boolean('remember'));
    }

    /**
     * Why this account may not sign in (blocked, or closed and waiting for
     * deletion), or null when it may. Shared with the passkey sign-in (P1-19).
     */
    public static function blockedMessage(User $user): ?string
    {
        if ((int) $user->status !== 0) {
            return null;
        }

        $pendingDeletion = AccountDeletion::pendingFor((int) $user->id);

        return $pendingDeletion
            ? ___('This account was closed and will be permanently deleted on :date. Use the restore link we emailed you to reopen it.', ['date' => $pendingDeletion->purge_after->translatedFormat('j F Y')])
            : ___('Your account is blocked');
    }

    /**
     * A language the visitor picked before signing in (plain `qc_lang_choice`
     * cookie) becomes the account's mail language, but only when the account
     * has none yet and the language is active. Never overwrites a choice.
     */
    public static function adoptVisitorLanguage(Request $request, User $user): void
    {
        if ($user->preferred_locale !== null) {
            return;
        }

        $choice = strtolower(trim((string) $request->cookie('qc_lang_choice', '')));

        if ($choice !== '' && Language::where('code', $choice)->where('active', 1)->exists()) {
            $user->preferred_locale = $choice;
            $user->save();
        }
    }
}
