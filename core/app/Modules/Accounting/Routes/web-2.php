<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-13 13c: moved from routes/web.php (slot Accounting/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// B3 accounting — QuickBooks/Xero sync connect + log (superadmin-gated).
Route::controller('\Juwri\Modules\Accounting\Http\Controllers\User\AccountingController')->name('stores.accounting.')->prefix('accounting')->middleware('feature:accounting_sync')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/connect', 'connect')->name('connect');
    Route::post('/disconnect', 'disconnect')->name('disconnect');
    Route::post('/retry/{orderId}', 'retry')->name('retry');
    Route::post('/retry-failed', 'retryAllFailed')->name('retry-failed');
});
