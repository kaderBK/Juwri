<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Tables/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* PARITY-RUN T10: QR Designer. Dashboard-only, so `feature:` middleware
               is the correct gate here — the storefront never reaches these routes
               and degrades to the plain design through store_qr_settings(). */
Route::controller('\Juwri\Modules\Tables\Http\Controllers\User\QrDesignerController')->name('qr.designer')->prefix('qr-designer')->middleware('feature:qr_designer')->group(function () {
    Route::get('/', 'index');
    Route::get('preview', 'preview')->name('.preview');
    Route::post('/', 'save')->name('.save');
    Route::post('reset', 'reset')->name('.reset');
    Route::get('download', 'download')->name('.download');
    Route::get('sheet', 'sheet')->middleware('ratelimit:export')->name('.sheet');
    Route::get('zip', 'zip')->middleware('ratelimit:export')->name('.zip');
    Route::post('rotate', 'rotateTable')->name('.rotate');
    Route::post('rotate-all', 'rotateAll')->name('.rotateAll');
});

// SYSTEMS-RUN W: owner waiter-mode console. Superadmin-gated.
Route::get('waiter-mode', '\Juwri\Modules\Tables\Http\Controllers\User\WaiterModeController@index')->middleware('feature:waiter_mode')->name('stores.waiter.index');
