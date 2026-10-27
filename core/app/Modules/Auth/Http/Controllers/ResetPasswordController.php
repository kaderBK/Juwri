<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Events\CredentialsChanged;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sets a new password from an emailed reset link. The broker checks the
 * token against its hashed copy, rejects expired tokens and deletes the
 * token once used. A successful reset rotates the remember-me token (so
 * "remember me" cookies issued before the reset stop working) and signs the
 * user in on a fresh session, except a blocked account (sent to the login
 * page) and a two-factor account (sent to the challenge first).
 */
class ResetPasswordController extends Controller
{
    public function showResetForm(Request $request, ?string $token = null): View
    {
        return view('auth.passwords.reset', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function reset(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', 'string', PasswordRule::defaults()],
        ] + validate_recaptcha());

        $resetUser = null;
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use (&$resetUser): void {
                $user->password = Hash::make($password);
                $user->setRememberToken(Str::random(60));
                $user->save();

                event(new PasswordReset($user));
                // P6-75: a reset also signs out every owner-app device (bearer tokens outlive web sessions).
                event(new CredentialsChanged($user, CredentialsChanged::PASSWORD_RESET));

                $resetUser = $user;
            }
        );

        if ($status !== Password::PASSWORD_RESET || ! $resetUser instanceof User) {
            if ($request->wantsJson()) {
                throw ValidationException::withMessages(['email' => [trans($status)]]);
            }

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => trans($status)]);
        }

        // WI-15-sec: the reset link proves the mailbox only. A blocked account
        // is not signed in, and a two-factor account still has to pass the
        // challenge (the same divert as a password sign-in).
        if ((int) $resetUser->status === 0) {
            return $request->wantsJson()
                ? new JsonResponse(['message' => trans($status)], 200)
                : redirect()->route('login')->with('status', trans($status));
        }

        if ($challenge = TwoFactorController::divertToChallenge($request, $resetUser)) {
            return $challenge instanceof RedirectResponse ? $challenge->with('status', trans($status)) : $challenge;
        }

        Auth::guard()->login($resetUser);

        return $request->wantsJson()
            ? new JsonResponse(['message' => trans($status)], 200)
            : redirect(RouteServiceProvider::USER)->with('status', trans($status));
    }
}
