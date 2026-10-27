<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Hr/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// SYSTEMS-RUN H: HRM employee file (profile/docs/rates/onboarding). Superadmin-gated.
Route::controller('\Juwri\Modules\Hr\Http\Controllers\User\StaffHrmController')->name('staff.hrm.')->prefix('staff/{id}/hrm')->middleware('feature:hrm_suite')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('profile', 'updateProfile')->name('profile');
    Route::post('rate', 'setRate')->name('rate');
    Route::post('documents', 'uploadDocument')->name('documents.store');
    Route::get('documents/{docId}/download', 'downloadDocument')->name('documents.download');
    Route::delete('documents/{docId}', 'destroyDocument')->name('documents.destroy');
    Route::post('onboarding/{itemId}', 'toggleOnboarding')->name('onboarding.toggle');
});

// SYSTEMS-RUN H-P2: leave / time-off. Superadmin-gated (hrm_suite).
Route::controller('\Juwri\Modules\Hr\Http\Controllers\User\LeaveController')->name('stores.leave.')->prefix('leave')->middleware('feature:hrm_suite')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('request', 'requestLeave')->name('request');
    Route::post('types', 'storeType')->name('types.store');
    Route::post('{id}/approve', 'approve')->name('approve');
    Route::post('{id}/reject', 'reject')->name('reject');
});
