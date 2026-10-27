<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Pos/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// B6 digital signage — owner screen config (superadmin-gated).
Route::controller('\Juwri\Modules\Pos\Http\Controllers\User\SignageAdminController')->name('stores.signage.')->prefix('signage')->middleware('feature:digital_signage')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::post('/{id}/regenerate', 'regenerate')->name('regenerate');
    Route::post('/{id}/toggle', 'toggle')->name('toggle');
    Route::delete('/{id}', 'destroy')->name('destroy');
});
