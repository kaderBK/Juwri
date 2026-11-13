<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Hr/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// SYSTEMS-RUN T-P3: rota upgrades — open shifts, claims, swaps. team_comms-gated.
Route::controller('\Juwri\Modules\Hr\Http\Controllers\User\RotaUpgradeController')->name('stores.rotaplus.')->prefix('rota-plus')->middleware('feature:team_comms')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('shifts/{id}/open', 'openShift')->name('open');
    Route::post('shifts/{id}/approve-claim', 'approveClaim')->name('approveClaim');
    Route::post('swaps/{id}/approve', 'approveSwap')->name('approveSwap');
});

// SYSTEMS-RUN P: payroll runs (engine + approval). Superadmin-gated.
Route::controller('\Juwri\Modules\Hr\Http\Controllers\User\PayrollController')->name('stores.payroll.')->prefix('payroll-runs')->middleware('feature:payroll_suite')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('generate', 'generate')->name('generate');
    Route::get('{id}', 'show')->name('show');
    Route::post('{id}/adjust', 'adjust')->name('adjust');
    Route::post('{id}/submit', 'submit')->name('submit');
    Route::post('{id}/approve', 'approve')->name('approve');
    Route::post('{id}/pool', 'pool')->name('pool');
    Route::post('{id}/mark-paid', 'markPaid')->name('markPaid');
    Route::get('{id}/bank-file', 'bankFile')->name('bankFile');
    Route::get('{id}/payslip/{itemId}', 'payslip')->name('payslip');
    Route::get('labor/dashboard', 'labor')->name('labor');
});
