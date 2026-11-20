<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Data\ResellerEmailGuard;

/**
 * "Forgot password": mails a reset link through Laravel's password broker
 * (tokens are hashed at rest, expire after auth.passwords.users.expire
 * minutes and are throttled per address by the broker).
 *
 * The answer is the same whether or not the address has an account, so
 * the form cannot be used to find out which addresses are registered.
 *
 * R7b takeover guard: after a reseller changed a customer's sign-in email, no
 * reset link is mailed while the reset pause runs
 * (EmailChangeUndos::RESELLER_RESET_PAUSE_SECONDS), nor ever to a
 * reseller-set address the customer has not verified. The visitor gets the
 * same generic answer (no enumeration). See ResellerEmailGuard::resetWithheld().
 */
class ForgotPasswordController extends Controller
{
    public function showLinkRequestForm(): View
    {
        return view('auth.passwords.email');
    }

    public function sendResetLinkEmail(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate(['email' => 'required|email'] + validate_recaptcha());

        $broker = Password::broker();
        $email = mb_strtolower(trim((string) $request->input('email')));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        // The broker's status (sent / unknown user / throttled / withheld) is
        // deliberately not shown to the visitor.
        if ($user instanceof User && ResellerEmailGuard::resetWithheld($user)) {
            Log::info('[forgot-password] reset link withheld after a reseller email change', ['user' => $user->id]);
        } else {
            $broker->sendResetLink($request->only('email'));
        }

        $message = ___('If that email address is in our system, we have sent a password reset link to it.');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
