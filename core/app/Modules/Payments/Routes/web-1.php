<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Payments/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* ===== end google-partner Lane B block ===== */

// P5-29: owner "Card terminals" page — set up the store's own SoftPOS account (Geidea / EdfaPay).
Route::get('card-terminals', '\Juwri\Modules\Payments\Http\Controllers\User\SoftPosAccountsController@show')->name('softpos.index');
// B11 disputes — owner chargeback list (superadmin-gated).
Route::get('disputes', '\Juwri\Modules\Payments\Http\Controllers\User\DisputeController@index')->middleware('feature:disputes')->name('stores.disputes.index');
// WIRING-RUN E6: in-app evidence submission (was "coming soon", dashboard-only).
Route::post('disputes/{dispute}/evidence', '\Juwri\Modules\Payments\Http\Controllers\User\DisputeController@submitEvidence')->middleware('feature:disputes')->name('stores.disputes.evidence');

// PAYMENTS-RUN G10: Paylink partner onboarding wizard (superadmin-gated).
Route::controller('\Juwri\Modules\Payments\Http\Controllers\User\PaylinkPartnerController')->name('stores.paylink.partner.')->prefix('paylink-partner')->middleware('feature:paylink_partner')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/submit', 'submit')->name('submit');
    Route::post('/disconnect', 'disconnect')->name('disconnect');
});
