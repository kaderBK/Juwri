<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use App\Models\StaffMember;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Models\Scopes\AccountScope;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Models\StaffPasskey;
use Juwri\Modules\Auth\Support\PasskeyChallenge;
use Juwri\Modules\Auth\Support\PasskeyRelyingParty;
use Juwri\Modules\Auth\Support\StaffPasskeys;
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
 * P1-19b: passkeys of staff members. A staff passkey signs in as THAT staff member and opens exactly the
 * session the staff password sign-in opens (StaffLoginController::startSession — staff guard + the
 * owner-fenced web guard, so the staff permissions apply, never the owner's full rights). Served where the
 * staff sign-in is: the dashboard host (and an active reseller domain in single-host mode), never the admin
 * host or storefront hosts.
 *
 * Same rules as owner passkeys (PasskeyController): server-side single-use challenges with a TTL, relying party
 * from the host, user verification required, sign counter must increase, per-IP lockout + auth-passkey limiter,
 * refused while impersonating, activity log. Inactive or deleted staff (and staff of a blocked owner) are
 * refused. Staff manage their own passkeys after re-proving their password; owners list and revoke them on the
 * staff page (User\StaffController).
 */
class StaffPasskeyController extends Controller
{
    private const LOGIN = 'staff-login';

    // ── Sign-in ───────────────────────────────────────────────────────────────

    public function loginOptions(Request $request): JsonResponse
    {
        $this->relyingParty($request);

        $options = StaffPasskeys::requestOptions();
        PasskeyChallenge::put($request, self::LOGIN, $options);

        return new JsonResponse(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function login(Request $request): JsonResponse
    {
        $this->relyingParty($request);

        $failures = 'staff-passkey-login|'.$request->ip();
        $window = (int) config('passkeys.failure_window', 60);
        if (RateLimiter::tooManyAttempts($failures, (int) config('passkeys.max_failures', 10))) {
            event(new Lockout($request));
            $seconds = RateLimiter::availableIn($failures);

            throw ValidationException::withMessages([
                'credential' => [trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)])],
            ])->status(Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $credential = PasskeyController::parseCredential($request, AuthenticatorAssertionResponse::class);
            /** @var PublicKeyCredentialRequestOptions $options */
            $options = PasskeyChallenge::pull($request, self::LOGIN, PublicKeyCredentialRequestOptions::class);
            $passkey = $this->verify($credential, $options);
        } catch (ValidationException $e) {
            RateLimiter::hit($failures, $window);

            throw $e;
        }

        $staff = StaffMember::withoutGlobalScope(AccountScope::class)->find($passkey->staff_id);
        if (! $staff instanceof StaffMember || $passkey->rp_id !== (string) config('passkeys.relying_party_id')) {
            RateLimiter::hit($failures, $window);

            throw $this->failed();
        }

        $owner = $staff->owner()->first();
        if (! $staff->active || ! $owner instanceof User || LoginController::blockedMessage($owner) !== null) {
            $message = ___('Invalid staff credentials or inactive account.');

            return new JsonResponse(['message' => $message, 'errors' => ['credential' => [$message]]], Response::HTTP_FORBIDDEN);
        }

        // Exactly the staff password sign-in's session. A passkey with user verification also stands in for
        // the staff member's TOTP (phishing-resistant MFA) and counts as a fresh proof for managing passkeys.
        StaffLoginController::startSession($request, $staff, $request->boolean('remember'));
        $request->session()->put(StaffLoginController::CONFIRMED_AT, now()->getTimestamp());
        RateLimiter::clear($failures);

        log_activity('auth.staff_passkey_login', $passkey, ['staff_id' => $staff->id], (int) $staff->store_id);
        $request->session()->flash('success', ___('Signed in as :name.', ['name' => $staff->name]));

        return new JsonResponse([
            'redirect' => route('dashboard'),
            'staff' => ['id' => $staff->id, 'name' => $staff->name],
        ]);
    }

    // ── The staff member's own passkeys ───────────────────────────────────────

    public function index(Request $request): View
    {
        $staff = $this->staff($request);

        return view('auth.staff-passkeys', [
            'staff' => $staff,
            'passkeys' => $staff->passkeys()->orderBy('created_at')->get(),
            'relyingParty' => $this->offered($request) ? PasskeyRelyingParty::for($request) : null,
            'confirmed' => $this->recentlyConfirmed($request),
        ]);
    }

    public function registerOptions(Request $request): JsonResponse
    {
        $staff = $this->staff($request);
        $this->relyingParty($request);

        if (! $this->recentlyConfirmed($request)) {
            $password = (string) $request->input('password', '');
            if ($password === '' || ! Hash::check($password, (string) $staff->password)) {
                $message = ___('Confirm your password to add a passkey.');

                return new JsonResponse(['message' => $message, 'errors' => ['password' => [$message]]], Response::HTTP_LOCKED);
            }
            $request->session()->put(StaffLoginController::CONFIRMED_AT, now()->getTimestamp());
        }

        if ($staff->passkeys()->count() >= (int) config('passkeys.max_per_user', 20)) {
            throw ValidationException::withMessages(['name' => ___('You have reached the maximum number of passkeys. Remove one first.')]);
        }

        $options = StaffPasskeys::registrationOptions($staff);
        PasskeyChallenge::put($request, 'staff-register.'.$staff->id, $options);

        return new JsonResponse(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function store(Request $request): JsonResponse
    {
        $staff = $this->staff($request);
        $this->relyingParty($request);

        $name = $this->validName($request);
        $credential = PasskeyController::parseCredential($request, AuthenticatorAttestationResponse::class);
        $rawId = Base64UrlSafe::encodeUnpadded($credential->rawId);
        if (strlen($rawId) > PasskeyController::MAX_CREDENTIAL_ID || StaffPasskeys::credentialTaken($rawId)) {
            throw $this->notAdded();
        }
        /** @var PublicKeyCredentialCreationOptions $options */
        $options = PasskeyChallenge::pull($request, 'staff-register.'.$staff->id, PublicKeyCredentialCreationOptions::class);

        if ($staff->passkeys()->count() >= (int) config('passkeys.max_per_user', 20)) {
            throw ValidationException::withMessages(['name' => ___('You have reached the maximum number of passkeys. Remove one first.')]);
        }

        try {
            $passkey = StaffPasskeys::store($staff, $name, $credential, $options);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::info('[passkeys] staff registration refused: '.$e->getMessage(), ['staff_id' => $staff->id]);

            throw $this->notAdded();
        }

        log_activity('auth.staff_passkey_added', $passkey, ['staff_id' => $staff->id], (int) $staff->store_id);

        return new JsonResponse([
            'message' => ___('Passkey added.'),
            'passkey' => ['id' => $passkey->id, 'name' => $passkey->name],
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, int $passkeyId): RedirectResponse|JsonResponse
    {
        $staff = $this->staff($request);
        /** @var StaffPasskey $passkey */
        $passkey = $staff->passkeys()->whereKey($passkeyId)->firstOrFail();

        $passkey->delete();
        log_activity('auth.staff_passkey_removed', $passkey, ['staff_id' => $staff->id, 'name' => $passkey->name], (int) $staff->store_id);

        return $request->expectsJson()
            ? new JsonResponse(['message' => ___('Passkey removed.')])
            : back()->with('success', ___('Passkey removed.'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Staff sign-in is served on the dashboard host (and reseller domains in single-host mode) only. */
    private function offered(Request $request): bool
    {
        $kind = PasskeyRelyingParty::for($request)['kind'] ?? null;

        return $kind === HostMap::APP || $kind === 'reseller';
    }

    private function relyingParty(Request $request): void
    {
        abort_unless($this->offered($request) && PasskeyRelyingParty::configure($request), 404);
    }

    /**
     * The signed-in staff member (staff guard + the matching staff session). Never while an admin or reseller
     * impersonates: a passkey added then would be a lasting way back in.
     */
    private function staff(Request $request): StaffMember
    {
        $staff = Auth::guard('staff')->user();
        $session = $request->session();
        abort_unless($staff instanceof StaffMember && $staff->active
            && (int) $session->get('staff_session_id') === (int) $staff->id, 403);
        abort_if($session->has('quick_admin_user_id') || $session->has('impersonating'), 403);

        return $staff;
    }

    private function recentlyConfirmed(Request $request): bool
    {
        $at = (int) $request->session()->get(StaffLoginController::CONFIRMED_AT, 0);

        return $at > 0 && (now()->getTimestamp() - $at) < (int) config('auth.password_timeout', 10800);
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

    private function verify(PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options): StaffPasskey
    {
        try {
            return StaffPasskeys::verify($credential, $options);
        } catch (QueryException $e) {
            throw $e;
        } catch (CounterException $e) {
            $id = Base64UrlSafe::encodeUnpadded($credential->rawId);
            Log::warning('[passkeys] staff sign counter did not increase; refused (possible cloned authenticator)', ['credential_id' => $id]);
            $passkey = StaffPasskey::withoutGlobalScope(AccountScope::class)->where('credential_id', $id)->first();
            log_activity('auth.staff_passkey_counter_regression', $passkey, ['ip' => request()->ip()], $passkey?->store_id);

            throw $this->failed();
        } catch (Throwable $e) {
            Log::info('[passkeys] staff assertion refused: '.$e->getMessage());

            throw $this->failed();
        }
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
