<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/auth.php (slot Auth/auth-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/*
 * Account routes: owner sign-in / sign-out, staff PIN sign-in, two-factor,
 * social sign-in, password reset, e-mail verification and sign-up.
 * Loaded from web.php inside the language-prefix group.
 */

// Owner sign-in (throttled by the auth-login limiter) and sign-out.
Route::controller('\Juwri\Modules\Auth\Http\Controllers\LoginController')->group(function () {
    Route::get('login', 'showLoginForm')->name('login');
    Route::post('login', 'login')->middleware('throttle:auth-login');
    Route::post('logout', 'logout')->name('logout');
});

Route::get('staff/login', '\Juwri\Modules\Auth\Http\Controllers\StaffLoginController@showLoginForm')->name('staff.login');
Route::post('staff/login', '\Juwri\Modules\Auth\Http\Controllers\StaffLoginController@login')->middleware('throttle:auth-login')->name('staff.login.attempt');
Route::post('staff/logout', '\Juwri\Modules\Auth\Http\Controllers\StaffLoginController@logout')->middleware('auth:staff')->name('staff.logout');
Route::get('staff/portal', '\Juwri\Modules\Auth\Http\Controllers\StaffLoginController@portal')->middleware('auth:staff')->name('staff.portal');

/* H8: TOTP two-factor auth. Challenge is session-gated (post-password); management
   endpoints require an authenticated session.
   WI-15-sec A-R1: a staff sign-in authenticates the web guard as the OWNER, so every
   owner-account route here also carries `staff.dashboard` (unmapped route names are
   default-deny for staff sessions; the owner passes). A-R3: enabling (which re-keys
   an enrolled account) and reading / regenerating recovery codes need a fresh
   password confirmation, like disabling. A-R6: the confirmation itself is throttled. */
Route::get('two-factor/challenge', '\Juwri\Modules\Auth\Http\Controllers\TwoFactorController@challenge')->name('2fa.challenge');
Route::post('two-factor/challenge', '\Juwri\Modules\Auth\Http\Controllers\TwoFactorController@verifyChallenge')->middleware('throttle:auth-login')->name('2fa.challenge.verify');
Route::middleware(['auth', 'staff.dashboard'])->group(function () {
    /* S8: re-authentication screen for the password.confirm middleware. */
    Route::get('password/confirm', '\Juwri\Modules\Auth\Http\Controllers\ConfirmablePasswordController@show')->name('password.confirm');
    Route::post('password/confirm', '\Juwri\Modules\Auth\Http\Controllers\ConfirmablePasswordController@store')->middleware('throttle:auth-login')->name('password.confirm.store');

    Route::get('two-factor', '\Juwri\Modules\Auth\Http\Controllers\TwoFactorController@show')->name('2fa.show');
    Route::post('two-factor/enable', '\Juwri\Modules\Auth\Http\Controllers\TwoFactorController@enable')->middleware('password.confirm')->name('2fa.enable');
    Route::post('two-factor/confirm', '\Juwri\Modules\Auth\Http\Controllers\TwoFactorController@confirm')->middleware('throttle:10,1,2fa-confirm')->name('2fa.confirm');
    Route::post('two-factor/disable', '\Juwri\Modules\Auth\Http\Controllers\TwoFactorController@disable')->middleware(['password.confirm', 'throttle:10,1,2fa-disable'])->name('2fa.disable'); // S8 re-auth
    Route::get('two-factor/recovery-codes', '\Juwri\Modules\Auth\Http\Controllers\TwoFactorController@recoveryCodes')->middleware('password.confirm')->name('2fa.recovery');
    Route::post('two-factor/recovery-codes', '\Juwri\Modules\Auth\Http\Controllers\TwoFactorController@regenerateRecoveryCodes')->middleware('password.confirm')->name('2fa.recovery.regenerate');
});

/* P1-19 (J25-13): passkeys (laravel/passkeys actions, juwri routes). Sign-in is a guest JSON flow next to
   the password form; management needs a signed-in owner session (never a staff session — staff sign-in
   authenticates the web guard as the OWNER) and a fresh password (or passkey) confirmation to add or remove.
   `passkeys/add` is where password.confirm sends a JSON request that was refused for a stale confirmation. */
Route::post('passkeys/login/options', '\Juwri\Modules\Auth\Http\Controllers\PasskeyController@loginOptions')->middleware(['guest', 'throttle:auth-passkey'])->name('passkey.login.options');
Route::post('passkeys/login', '\Juwri\Modules\Auth\Http\Controllers\PasskeyController@login')->middleware(['guest', 'throttle:auth-passkey'])->name('passkey.login.verify');
Route::middleware(['auth', 'staff.dashboard'])->group(function () {
    Route::post('passkeys/confirm/options', '\Juwri\Modules\Auth\Http\Controllers\PasskeyController@confirmOptions')->middleware('throttle:auth-passkey')->name('passkeys.confirm.options');
    Route::post('passkeys/confirm', '\Juwri\Modules\Auth\Http\Controllers\PasskeyController@confirm')->middleware('throttle:auth-passkey')->name('passkeys.confirm');
    Route::get('passkeys/add', '\Juwri\Modules\Auth\Http\Controllers\PasskeyController@add')->middleware('password.confirm')->name('passkeys.add');
    Route::post('passkeys/options', '\Juwri\Modules\Auth\Http\Controllers\PasskeyController@registerOptions')->middleware(['password.confirm', 'throttle:auth-passkey'])->name('passkeys.options');
    Route::post('passkeys', '\Juwri\Modules\Auth\Http\Controllers\PasskeyController@store')->middleware(['password.confirm', 'throttle:auth-passkey'])->name('passkeys.store');
    Route::patch('passkeys/{passkeyId}', '\Juwri\Modules\Auth\Http\Controllers\PasskeyController@update')->whereNumber('passkeyId')->name('passkeys.update');
    Route::delete('passkeys/{passkeyId}', '\Juwri\Modules\Auth\Http\Controllers\PasskeyController@destroy')->whereNumber('passkeyId')->middleware('password.confirm')->name('passkeys.destroy');
});

/* P1-19b: staff passkeys. Sign-in next to the staff password form (same staff session); the staff member's
   own passkeys behind the staff guard (`auth:staff` — never an owner session). Owners revoke them from the
   staff page (staff.passkeys.revoke, owner dashboard routes). */
Route::post('staff/passkeys/login/options', '\Juwri\Modules\Auth\Http\Controllers\StaffPasskeyController@loginOptions')->middleware('throttle:auth-passkey')->name('staff.passkey.login.options');
Route::post('staff/passkeys/login', '\Juwri\Modules\Auth\Http\Controllers\StaffPasskeyController@login')->middleware('throttle:auth-passkey')->name('staff.passkey.login.verify');
Route::middleware('auth:staff')->group(function () {
    Route::get('staff/passkeys', '\Juwri\Modules\Auth\Http\Controllers\StaffPasskeyController@index')->name('staff.passkeys.index');
    Route::post('staff/passkeys/options', '\Juwri\Modules\Auth\Http\Controllers\StaffPasskeyController@registerOptions')->middleware('throttle:auth-passkey')->name('staff.passkeys.options');
    Route::post('staff/passkeys', '\Juwri\Modules\Auth\Http\Controllers\StaffPasskeyController@store')->middleware('throttle:auth-passkey')->name('staff.passkeys.store');
    Route::delete('staff/passkeys/{passkeyId}', '\Juwri\Modules\Auth\Http\Controllers\StaffPasskeyController@destroy')->whereNumber('passkeyId')->name('staff.passkeys.destroy');
});
