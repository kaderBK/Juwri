<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Customers/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* WI-A3 (upsell-visibility): the RFM READ is outside the gate so an
               unentitled owner can see it as a blurred preview of their own
               aggregates. The gate moves INTO the controller, which serves a
               PII-free aggregate payload in that case. Everything that writes,
               exports or reads a single guest stays gated below. */
Route::controller('\Juwri\Modules\Customers\Http\Controllers\User\CrmController')->name('crm.')->prefix('crm')->group(function () {
    Route::get('rfm', 'rfm')->name('rfm');
});

// SYSTEMS-RUN C: unified guest CRM (profile timeline + RFM). Superadmin-gated.
Route::controller('\Juwri\Modules\Customers\Http\Controllers\User\CrmController')->name('crm.')->prefix('crm')->middleware('feature:crm_suite')->group(function () {
    Route::post('retag', 'retag')->name('retag');
    Route::get('customers/{id}', 'profile')->name('profile');
    Route::post('customers/{id}/preferences', 'updatePreferences')->name('preferences');
    Route::get('audiences', 'audiences')->name('audiences');
    Route::post('audiences', 'storeAudience')->name('audiences.store');
    Route::post('audiences/preview', 'previewAudience')->name('audiences.preview');
    Route::delete('audiences/{id}', 'destroyAudience')->name('audiences.destroy');
});

// P6-209: owner handles a guest's (no account) privacy request; the phone is proven by a one-time code.
Route::controller('\\Juwri\\Modules\\Customers\\Http\\Controllers\\User\\GuestPrivacyController')->prefix('customers-privacy')->name('customers.guestPrivacy')->group(function () {
    Route::get('/', 'index')->name('');
    Route::post('code', 'sendCode')->middleware('throttle:10,10,guest-privacy-code')->name('.code');
    Route::post('verify', 'verify')->middleware('throttle:10,10,guest-privacy-verify')->name('.verify');
    Route::post('export', 'export')->name('.export');
    Route::post('erase', 'erase')->name('.erase');
});
