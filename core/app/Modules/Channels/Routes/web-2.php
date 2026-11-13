<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Channels/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// T3 aggregator hub — owner channel management.
Route::controller('\Juwri\Modules\Channels\Http\Controllers\User\AggregatorAdminController')->name('stores.aggregators.')->prefix('aggregators')->group(function () {
    Route::get('/', 'index')->middleware('feature:aggregator_hub')->name('index');
    Route::post('/connect', 'connect')->name('connect');
    Route::post('/{id}/pause', 'pause')->name('pause');
    Route::post('/{id}/menu-push', 'menuPush')->name('menuPush');
    Route::delete('/{id}', 'disconnect')->name('disconnect');
});
