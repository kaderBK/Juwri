<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Accounting/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// SYSTEMS-RUN A: built-in GL accounting (books) — expenses now,
// journals/reports/VAT/dashboard land in A-P3/A-P4. Superadmin-gated.
Route::controller('\Juwri\Modules\Accounting\Http\Controllers\User\GlAccountingController')->name('stores.books.')->prefix('books')->middleware('feature:accounting_gl')->group(function () {
    Route::get('/', 'dashboard')->name('index');
    Route::get('expenses', 'expensesIndex')->name('expenses');
    Route::post('expenses', 'storeExpense')->name('expenses.store');
    Route::delete('expenses/{id}', 'destroyExpense')->name('expenses.destroy');
    Route::get('expenses/{id}/receipt', 'downloadReceipt')->name('expenses.receipt'); // private, owner-scoped serve
    Route::get('journals', 'journals')->name('journals');
    Route::get('reports', 'reports')->name('reports');
    Route::post('period/lock', 'lockPeriod')->name('period.lock');
    Route::post('period/unlock', 'unlockPeriod')->name('period.unlock');
    Route::get('export', 'export')->name('export'); // P6-274: accountant CSV
});
