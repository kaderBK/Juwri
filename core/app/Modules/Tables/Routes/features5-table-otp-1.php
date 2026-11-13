<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
// Shared throttle prefixes on purpose (one counter across all routes using it): otp-send (every customer-OTP send, one limit of 5 a minute); otp-verify (every customer-OTP verify, one limit of 10 a minute).

// P1-09: moved from routes/features5/table-otp.php (slot Tables/features5-table-otp-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Tables\Http\Controllers\TableVerifyController;
use Juwri\Modules\Tables\Http\Controllers\User\TableVerificationController;

/* -------------------------------------------------------------------------
 | Owner dashboard — Settings → Tables → Verification
 | Mirrors the User route group's middleware (this file is required outside it).
 * ---------------------------------------------------------------------- */
// WV.V: + `app.locale` — see the note in routes/features5/menu-schedules.php.
// Without it this owner page rendered English LTR inside an Arabic RTL dashboard.
Route::middleware(['web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard', 'feature:table_otp'])
    ->prefix('stores/{store}/table-verification')
    ->name('stores.tableVerify.')
    ->group(function () {
        Route::get('/', [TableVerificationController::class, 'index'])->name('index');
        Route::post('mode', [TableVerificationController::class, 'saveMode'])->name('mode');
        Route::post('rotate', [TableVerificationController::class, 'rotate'])->name('rotate');
        Route::post('rotate-all', [TableVerificationController::class, 'rotateAll'])->name('rotateAll');
        Route::get('tent-cards', [TableVerificationController::class, 'tentCards'])->name('tentCards');
    });

/* -------------------------------------------------------------------------
 | Public guest verification (from the table QR). Localised like the public menu.
 | Throttles here are a coarse HTTP guard; the real per-table/IP attempt budget
 | lives in TableSessionService and the per-phone OTP caps in SmsOtpService.
 * ---------------------------------------------------------------------- */
Route::middleware(['web', 'app.locale'])
    ->prefix('{store}/table-verify')
    ->name('table.verify.')
    ->group(function () {
        Route::post('code', [TableVerifyController::class, 'verifyCode'])
            ->middleware(['throttle:20,1,tables-code', 'ratelimit:public-write'])->name('code');
        Route::post('otp/send', [TableVerifyController::class, 'sendOtp'])
            ->middleware(['throttle:5,1,otp-send', 'ratelimit:public-write', 'storecap:sms'])->name('otp.send');
        Route::post('otp/verify', [TableVerifyController::class, 'verifyOtp'])
            ->middleware(['throttle:10,1,otp-verify', 'ratelimit:public-write'])->name('otp.verify');
    });
