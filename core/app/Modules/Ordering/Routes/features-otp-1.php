<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
// Shared throttle prefixes on purpose (one counter across all routes using it): otp-send (every customer-OTP send, one limit of 5 a minute); otp-verify (every customer-OTP verify, one limit of 10 a minute).

// P1-09: moved from routes/features/otp.php (slot Ordering/features-otp-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/*
 * theme-pack-blade · otp feature routes.
 * Owned exclusively by its feature task — parallel agents must not edit
 * any other file under routes/. Registered by the glob in routes/web.php.
 *
 * F7 · OTP checkout gate. Thin wiring over the existing SmsOtpService.
 */

// throttle here is a coarse HTTP guard; the real per-phone rate-limit,
// resend cooldown and attempt cap live inside SmsOtpService.
Route::post('checkout/otp/send', '\Juwri\Modules\Ordering\Http\Controllers\CheckoutOtpController@send')
    // P6-261: the per-store SMS cap is counted inside the controller, after the body store id is validated
    ->middleware('throttle:5,1,otp-send')
    ->name('checkout.otp.send');

Route::post('checkout/otp/verify', '\Juwri\Modules\Ordering\Http\Controllers\CheckoutOtpController@verify')
    ->middleware('throttle:10,1,otp-verify')
    ->name('checkout.otp.verify');
