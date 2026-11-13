<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Modules\Auth\Models\Passkey;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Support\PasskeyChallenge;
use Juwri\Modules\Auth\Support\PasskeyRelyingParty;
use Juwri\Modules\Auth\Support\PasskeySession;
use Juwri\Modules\Auth\Support\StaffPasskeys;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Hosts\HostMap;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * P1-19 (J25-13): passkey sign-in, passkey management and passkey re-confirmation for `users` accounts,
 * on top of the laravel/passkeys actions (its own routes are off).
 *
 * Sign-in runs the same rules as the password sign-in (LoginController): blocked / closed accounts are
 * refused, HOST_MODE=split host rules (superadmins only on the admin host, never on the app host), the
 * auth-passkey route limiter plus a per-IP failure lockout, a fresh session id, the Login event (privileged
 * sign-in audit + new-device mail) and an activity-log entry. A passkey with user verification is
 * phishing-resistant multi-factor sign-in, so the TOTP challenge is not asked (see PasskeySession).
 *
 * Challenges are server-side, single use and short-lived (PasskeyChallenge); the relying party is the
 * platform host serving the request (PasskeyRelyingParty); sign counters must move forward (a regression
 * is refused and logged as a possible cloned authenticator).
 */
class PasskeyController extends Controller
{
    /** A credential id longer than this (base64url characters) is refused: it must fit the unique column. */
    public const MAX_CREDENTIAL_ID = 255;

    // ── Sign-in ───────────────────────────────────────────────────────────────

    /** Options for a passkey sign-in (discoverable credentials, so no username is asked first). */
    public function loginOptions(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $this->relyingParty($request);

        $options = $generate();
        PasskeyChallenge::put($request, PasskeyChallenge::LOGIN, $options);

        return new JsonResponse(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function login(Request $request, VerifyPasskey $verify): JsonResponse
    {
        $this->relyingParty($request);

        $failures = 'passkey-login|'.$request->ip();
        if (RateLimiter::tooManyAttempts($failures, (int) config('passkeys.max_failures', 10))) {
            event(new Lockout($request));
            $seconds = RateLimiter::availableIn($failures);

            throw ValidationException::withMessages([
                'credential' => [trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)])],
            ])->status(Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $credential = self::parseCredential($request, AuthenticatorAssertionResponse::class);
            /** @var PublicKeyCredentialRequestOptions $options */
            $options = PasskeyChallenge::pull($request, PasskeyChallenge::LOGIN, PublicKeyCredentialRequestOptions::class);
            $passkey = $this->verify($verify, $credential, $options);
        } catch (ValidationException $e) {
            RateLimiter::hit($failures, (int) config('passkeys.failure_window', 60));

            throw $e;
        }

        $user = $passkey->user;
        if (! $user instanceof User || $passkey->rp_id !== (string) config('passkeys.relying_party_id')) {
            RateLimiter::hit($failures, (int) config('passkeys.failure_window', 60));

            throw $this->failed();
        }

        if (($blocked = LoginController::blockedMessage($user)) !== null) {
            return new JsonResponse(['message' => $blocked, 'errors' => ['credential' => [$blocked]]], Response::HTTP_FORBIDDEN);
        }

        if (($refused = $this->wrongHost($request, $user)) !== null) {
            return $refused;
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        RateLimiter::clear($failures);

        // A passkey with user verification proves more than a password: the password-confirm window
        // starts now, the TOTP challenge and the reseller portal's 2FA gate are satisfied for this session.
        $request->session()->forget(['login.2fa.id', 'login.2fa.key', 'login.2fa.remember']);
        $request->session()->put('auth.password_confirmed_at', time());
        $request->session()->put(ResellerContext::TWO_FACTOR_PASSED, true);
        PasskeySession::mark($request, $user);

        LoginController::adoptVisitorLanguage($request, $user);
        log_activity('auth.passkey_login', $passkey, ['user_id' => $user->id, 'rp_id' => $passkey->rp_id]);

        return new JsonResponse([
            'redirect' => redirect()->intended(LoginController::homeFor($user))->getTargetUrl(),
        ]);
    }

    // ── Re-confirmation (instead of the password, for password.confirm) ───────

    public function confirmOptions(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $this->relyingParty($request);
        $user = $this->user($request);

        if (! $user->passkeys()->where('rp_id', config('passkeys.relying_party_id'))->exists()) {
            throw ValidationException::withMessages(['credential' => ___('You have no passkey for this site yet.')]);
        }

        $options = $generate($user);
        PasskeyChallenge::put($request, PasskeyChallenge::CONFIRM, $options);

        return new JsonResponse(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function confirm(Request $request, VerifyPasskey $verify): JsonResponse
    {
        $this->relyingParty($request);
        $user = $this->user($request);

        $credential = self::parseCredential($request, AuthenticatorAssertionResponse::class);
        /** @var PublicKeyCredentialRequestOptions $options */
        $options = PasskeyChallenge::pull($request, PasskeyChallenge::CONFIRM, PublicKeyCredentialRequestOptions::class);
        $this->verify($verify, $credential, $options, $user);

        $request->session()->put('auth.password_confirmed_at', time());

        return new JsonResponse(['redirect' => redirect()->intended(route('dashboard'))->getTargetUrl()]);
    }

    // ── Management (security settings) ────────────────────────────────────────

    /** password.confirm sends the person back here; continue to the security page that started it. */
    public function add(Request $request): RedirectResponse
    {
        $this->manageableUser($request);

        return $request->query('from') === 'reseller' && Route::has('reseller.security')
            ? redirect()->route('reseller.security')
            : redirect()->route('2fa.show');
    }

    public function registerOptions(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $this->relyingParty($request);
        $user = $this->manageableUser($request);

        if ($user->passkeys()->count() >= (int) config('passkeys.max_per_user', 20)) {
            throw ValidationException::withMessages(['name' => ___('You have reached the maximum number of passkeys. Remove one first.')]);
        }

        $options = $generate($user);
        PasskeyChallenge::put($request, PasskeyChallenge::REGISTER, $options);

        return new JsonResponse(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function store(Request $request, StorePasskey $store): JsonResponse
    {
        $this->relyingParty($request);
        $user = $this->manageableUser($request);

        $name = $this->validName($request);
        $credential = self::parseCredential($request, AuthenticatorAttestationResponse::class);
        $rawId = Base64UrlSafe::encodeUnpadded($credential->rawId);
        // P1-19b: credential ids are unique across user AND staff passkeys.
        if (strlen($rawId) > self::MAX_CREDENTIAL_ID || StaffPasskeys::credentialTaken($rawId)) {
            throw $this->notAdded();
        }
        /** @var PublicKeyCredentialCreationOptions $options */
        $options = PasskeyChallenge::pull($request, PasskeyChallenge::REGISTER, PublicKeyCredentialCreationOptions::class);

        if ($user->passkeys()->count() >= (int) config('passkeys.max_per_user', 20)) {
            throw ValidationException::withMessages(['name' => ___('You have reached the maximum number of passkeys. Remove one first.')]);
        }

        try {
            /** @var Passkey $passkey */
            $passkey = $store($user, $name, $credential, $options);
        } catch (QueryException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::info('[passkeys] registration refused: '.$e->getMessage(), ['user_id' => $user->id]);

            throw $this->notAdded();
        }

        log_activity('auth.passkey_added', $passkey, ['rp_id' => $passkey->rp_id]);

        return new JsonResponse([
            'message' => ___('Passkey added.'),
            'passkey' => ['id' => $passkey->id, 'name' => $passkey->name],
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, int $passkeyId): JsonResponse|RedirectResponse
    {
        $user = $this->manageableUser($request);
        $passkey = $this->ownPasskey($user, $passkeyId);

        $passkey->forceFill(['name' => $this->validName($request)])->save();
        log_activity('auth.passkey_renamed', $passkey);

        return $request->expectsJson()
            ? new JsonResponse(['message' => ___('Passkey renamed.')])
            : back()->with('success', ___('Passkey renamed.'));
    }

    public function destroy(Request $request, int $passkeyId, DeletePasskey $delete): JsonResponse|RedirectResponse
    {
        $user = $this->manageableUser($request);
        $passkey = $this->ownPasskey($user, $passkeyId);

        $delete($user, $passkey);
        log_activity('auth.passkey_removed', $passkey, ['name' => $passkey->name]);

        return $request->expectsJson()
            ? new JsonResponse(['message' => ___('Passkey removed.')])
            : back()->with('success', ___('Passkey removed.'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Point the package at this host's relying party; passkeys are not offered on other hosts. */
    private function relyingParty(Request $request): void
    {
        abort_unless(PasskeyRelyingParty::configure($request), 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * The signed-in account managing its own passkeys. Never while an admin or reseller is viewing the
     * account as its owner: a passkey added then would be a lasting way back in.
     */
    private function manageableUser(Request $request): User
    {
        $user = $this->user($request);
        $session = $request->session();
        abort_if($session->has('quick_admin_user_id') || $session->has('impersonating'), 403);

        return $user;
    }

    private function ownPasskey(User $user, int $passkeyId): Passkey
    {
        /** @var Passkey $passkey */
        $passkey = $user->passkeys()->whereKey($passkeyId)->firstOrFail();

        return $passkey;
    }

    private function validName(Request $request): string
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $name = trim(preg_replace('/\s+/u', ' ', (string) $data['name']) ?? '');
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ___('Give the passkey a name.')]);
        }

        return $name;
    }

    /**
     * The browser's PublicKeyCredential (JSON form), of the expected ceremony.
     *
     * @param  class-string  $responseClass
     */
    public static function parseCredential(Request $request, string $responseClass): PublicKeyCredential
    {
        $request->validate([
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string', 'max:2048'],
            'credential.rawId' => ['required', 'string', 'max:2048'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ]);

        try {
            $credential = WebAuthn::fromJson(
                json_encode($request->input('credential'), JSON_THROW_ON_ERROR),
                PublicKeyCredential::class
            );
        } catch (Throwable) {
            $credential = null;
        }

        if (! $credential instanceof PublicKeyCredential || ! $credential->response instanceof $responseClass) {
            throw ValidationException::withMessages(['credential' => ___('This passkey could not be read. Please try again.')]);
        }

        return $credential;
    }

    /** Run the package's assertion check; every refusal becomes one generic message. */
    private function verify(VerifyPasskey $verify, PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options, ?User $user = null): Passkey
    {
        try {
            /** @var Passkey $passkey */
            $passkey = $verify($credential, $options, $user);

            return $passkey;
        } catch (QueryException $e) {
            throw $e;
        } catch (CounterException $e) {
            // The authenticator's counter went backwards: possibly a cloned key. Refuse and leave a trail.
            $id = Base64UrlSafe::encodeUnpadded($credential->rawId);
            Log::warning('[passkeys] sign counter did not increase; refused (possible cloned authenticator)', ['credential_id' => $id]);
            log_activity('auth.passkey_counter_regression', Passkey::where('credential_id', $id)->first(), ['ip' => request()->ip()]);

            throw $this->failed();
        } catch (Throwable $e) {
            Log::info('[passkeys] assertion refused: '.$e->getMessage());

            throw $this->failed();
        }
    }

    /** HOST_MODE=split (J28): superadmins sign in on the admin host only, everyone else never there. */
    private function wrongHost(Request $request, User $user): ?JsonResponse
    {
        if (! HostMap::split()) {
            return null;
        }

        $kind = PasskeyRelyingParty::for($request)['kind'] ?? null;
        if ($kind === HostMap::APP && $user->isAdmin()) {
            $message = ___('Superadmin accounts sign in on the admin site.');
            $target = HostMap::adminUrl().'/login';
        } elseif ($kind === HostMap::ADMIN && ! $user->isAdmin()) {
            $message = ___('Sign in on the dashboard site.');
            $target = HostMap::appUrl().'/login';
        } else {
            return null;
        }

        return new JsonResponse(['message' => $message, 'errors' => ['credential' => [$message]], 'redirect' => $target], Response::HTTP_FORBIDDEN);
    }

    private function failed(): ValidationException
    {
        return ValidationException::withMessages(['credential' => ___('This passkey could not be verified. Try again or sign in with your password.')]);
    }

    private function notAdded(): ValidationException
    {
        return ValidationException::withMessages(['credential' => ___('This passkey could not be added. Please try again.')]);
    }
}
