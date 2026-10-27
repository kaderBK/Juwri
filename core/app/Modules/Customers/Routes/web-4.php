<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
// Shared throttle prefixes on purpose (one counter across all routes using it): otp-send (every customer-OTP send, one limit of 5 a minute); otp-verify (every customer-OTP verify, one limit of 10 a minute).

// P1-09: moved from routes/web.php (slot Customers/web-4); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* RESTAURANT PUBLIC ROUTES */
Route::post('{store}/customer/login', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@login')->middleware('throttle:auth-login')->name('customer.login');
// #104: SMS login OTP
Route::post('{store}/customer/otp/request', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@requestOtp')->middleware(['throttle:5,1,otp-send', 'ratelimit:public-write', 'storecap:sms'])->name('customer.otp.request');
Route::post('{store}/customer/otp/verify', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@verifyOtp')->middleware(['throttle:10,1,otp-verify', 'ratelimit:public-write'])->name('customer.otp.verify');
Route::post('{store}/customer/register', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@register')->middleware('throttle:auth-register')->name('customer.register');
// GOL A4: signed email-verification link that claims a shadow account's orders.
// Throttled (F1): a valid link must not be replayable to hammer the login+claim path.
Route::get('{store}/customer/claim/verify/{customer}', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@verifyClaim')->middleware(['signed', 'throttle:10,1,customers-customer-claim-verify'])->name('customer.claim.verify');
Route::post('{store}/customer/logout', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@logout')->name('customer.logout');
/* P1-19 (customer passkeys): sign in to THIS store with a passkey (next to the password / phone-code forms,
   which are unchanged), and add / remove passkeys from the account page. Relying party from the host serving
   the store page (J28); the controller checks the store scope and the customer guard. */
Route::post('{store}/customer/passkeys/login/options', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerPasskeyController@loginOptions')->middleware('throttle:customer-passkey')->name('customer.passkey.login.options');
Route::post('{store}/customer/passkeys/login', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerPasskeyController@login')->middleware('throttle:customer-passkey')->name('customer.passkey.login.verify');
Route::post('{store}/customer/passkeys/options', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerPasskeyController@registerOptions')->middleware('throttle:customer-passkey')->name('customer.passkeys.options');
Route::post('{store}/customer/passkeys/code', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerPasskeyController@sendCode')->middleware(['throttle:customer-passkey', 'throttle:5,1,otp-send', 'storecap:sms'])->name('customer.passkeys.code');
Route::post('{store}/customer/passkeys', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerPasskeyController@store')->middleware('throttle:customer-passkey')->name('customer.passkeys.store');
Route::delete('{store}/customer/passkeys/{passkeyId}', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerPasskeyController@destroy')->whereNumber('passkeyId')->middleware('throttle:customer-passkey')->name('customer.passkeys.destroy');
Route::get('{store}/customer/account', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@account')->name('customer.account');
// Standalone account-settings page (profile/addresses/preferences/language/
// appearance/info) — separate screen from the orders page above.
Route::get('{store}/customer/profile', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@profilePage')->name('customer.profile');
