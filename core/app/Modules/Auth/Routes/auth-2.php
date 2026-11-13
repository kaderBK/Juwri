<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/auth.php (slot Auth/auth-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// Owner social sign-in; must stay after the more specific auth/social/customer/* paths.
Route::controller('\Juwri\Modules\Auth\Http\Controllers\SocialLoginController')->prefix('auth/{provider}')->group(function () {
    Route::get('/', 'redirect')->name('social.login');
    Route::get('callback', 'callback')->name('social.callback');
});

/* ===== google-suite Lane A (additive) — owner account social connect/disconnect =====
   Distinct from social login/register above: these link/unlink a provider identity to
   the CURRENTLY authenticated owner (dashboard profile "Connect Google"). Auth-guarded;
   WI-15-sec A-R1: never from a staff session (a staff member linking their own Google
   identity to the owner account could then sign in as the owner). */
Route::middleware(['auth', 'staff.dashboard'])->group(function () {
    Route::get('account/social/{provider}/connect', '\Juwri\Modules\Auth\Http\Controllers\SocialLoginController@connectRedirect')
        ->middleware('throttle:30,1,auth-account-social-connect')->name('social.connect');
    Route::post('account/social/{provider}/disconnect', '\Juwri\Modules\Auth\Http\Controllers\SocialLoginController@disconnect')
        ->name('social.disconnect');
});
/* ===== end google-suite Lane A block ===== */

// Forgotten password: ask for a link, then set a new password from the e-mailed token.
Route::controller('\Juwri\Modules\Auth\Http\Controllers\ForgotPasswordController')->group(function () {
    Route::get('password/reset', 'showLinkRequestForm')->name('password.request');
    Route::post('password/email', 'sendResetLinkEmail')->middleware('throttle:auth-password-email')->name('password.email');
});
Route::controller('\Juwri\Modules\Auth\Http\Controllers\ResetPasswordController')->group(function () {
    Route::get('password/reset/{token}', 'showResetForm')->name('password.reset');
    Route::post('password/reset', 'reset')->middleware('throttle:auth-password-reset')->name('password.update');
});

// E-mail address verification link and "send it again".
Route::controller('\Juwri\Modules\Auth\Http\Controllers\VerificationController')->prefix('email')->group(function () {
    Route::get('verify/{id}/{hash}', 'verify')->name('verification.verify');
    Route::post('resend', 'resend')->name('verification.resend');
});

// Sign-up, closed as a whole when the platform turns registration off.
Route::controller('\Juwri\Modules\Auth\Http\Controllers\RegisterController')
    ->middleware('checkRegistrationDisabled')
    ->prefix('signup')
    ->group(function () {
        Route::get('/', 'showRegistrationForm')->name('register');
        Route::post('/', 'register')->middleware('throttle:auth-register');
        Route::post('check_availability', 'checkAvailability')->middleware('throttle:public-check')->name('register.check_availability');
    });

// BL-036 A1a: "This wasn't me — undo" on the old-address notice after a sign-in email change.
// Guest; relative signature (the link is always built on app.url); GET = confirm page only,
// POST (CSRF) performs. The single-use token is checked in EmailChangeUndoService.
Route::get('account/email-undo/{undo}', '\Juwri\Modules\Auth\Http\Controllers\EmailChangeUndoController@show')
    ->middleware(['signed:relative', 'throttle:10,1,auth-email-undo', 'csp.marketing'])->whereNumber('undo')->name('account.email-undo');
Route::post('account/email-undo/{undo}', '\Juwri\Modules\Auth\Http\Controllers\EmailChangeUndoController@perform')
    ->middleware(['signed:relative', 'throttle:10,1,auth-email-undo', 'csp.marketing'])->whereNumber('undo')->name('account.email-undo.perform');
