<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features5/menu-schedules.php (slot Loyalty/features5-menu-schedules-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

Route::middleware([
    /* WV.V — `app.locale` is NOT part of the User route group's own
           middleware list; it sits on the OUTER group in routes/web.php that
           wraps it. This file is required outside that wrapper, so mirroring
           only the inner list left the locale middleware off and this page
           rendered `lang="en" dir="ltr"` inside an Arabic RTL dashboard —
           English text AND a broken layout. Caught in the browser; no request
           test sees it, because tests set the locale directly. */
    'web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard',
    'reseller.suspension', 'abuse.hold', 'feature:menu_schedules',
])
    ->controller('\Juwri\Modules\Loyalty\Http\Controllers\User\PriceScheduleController')
    ->name('stores.schedules.')
    ->prefix('schedules')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/options', 'options')->name('options');
        Route::post('/{id}', 'update')->whereNumber('id')->name('update');
        Route::post('/{id}/toggle', 'toggle')->whereNumber('id')->name('toggle');
        Route::delete('/{id}', 'destroy')->whereNumber('id')->name('destroy');
    });
