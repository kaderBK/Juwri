<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use Juwri\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * S8: the re-authentication screen behind the `password.confirm` middleware.
 * There was no `password.confirm` route in the app, so attaching Laravel's
 * RequirePassword middleware to a sensitive action (API-token create, 2FA
 * disable, reseller impersonation) would have 500'd. This is the standard
 * Breeze-style confirm flow it redirects to.
 */
class ConfirmablePasswordController extends Controller
{
    /** Show the confirm-password form. */
    public function show(Request $request)
    {
        return view('auth.confirm-password', ['activeTheme' => active_theme()]);
    }

    /** Verify the current user's password and stamp the confirmation. */
    public function store(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        if (! Hash::check($request->input('password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => ___('The provided password is incorrect.'),
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard'));
    }
}
