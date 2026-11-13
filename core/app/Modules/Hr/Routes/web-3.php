<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Hr/web-3); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// B7 staff rota — week grid + publish (superadmin-gated).
Route::controller('\Juwri\Modules\Hr\Http\Controllers\User\RotaController')->name('stores.rota.')->prefix('rota')->middleware('feature:staff_rota')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::delete('/{id}', 'destroy')->name('destroy');
    Route::post('/publish', 'publish')->name('publish');
    Route::get('/payroll', 'payroll')->name('payroll');
    Route::get('/payroll/csv', 'payrollCsv')->name('payroll.csv');
});
