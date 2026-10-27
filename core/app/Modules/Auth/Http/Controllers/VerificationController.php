<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;

/**
 * Email address confirmation for owner accounts (User implements
 * MustVerifyEmail). The link in the verification mail is a temporary signed
 * route; EmailVerificationRequest additionally checks that the id and email
 * hash belong to the signed-in user, so a link only ever verifies its own
 * account.
 */
class VerificationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('signed')->only('verify');
        $this->middleware('throttle:auth-verification')->only('verify', 'resend');
    }

    public function verify(EmailVerificationRequest $request): JsonResponse|RedirectResponse
    {
        $alreadyVerified = $request->user()->hasVerifiedEmail();

        // Marks the address verified and fires the Verified event.
        $request->fulfill();

        if ($request->wantsJson()) {
            return new JsonResponse([], 204);
        }

        $redirect = redirect(RouteServiceProvider::USER);

        return $alreadyVerified ? $redirect : $redirect->with('verified', true);
    }

    public function resend(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
            app_alert_success(___('Email resent successfully.'));
        }

        return back();
    }
}
