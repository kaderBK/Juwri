<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use Juwri\Core\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * "Forgot password": mails a reset link through Laravel's password broker
 * (tokens are hashed at rest, expire after auth.passwords.users.expire
 * minutes and are throttled per address by the broker).
 *
 * The answer is the same whether or not the address has an account, so
 * the form cannot be used to find out which addresses are registered.
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

        // The broker's status (sent / unknown user / throttled) is deliberately
        // not shown to the visitor.
        Password::broker()->sendResetLink($request->only('email'));

        $message = ___('If that email address is in our system, we have sent a password reset link to it.');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
