<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Channels/web-3); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// B1 POS bridge — push our orders into Square/Clover (superadmin-gated).
Route::controller('\Juwri\Modules\Channels\Http\Controllers\User\PosBridgeController')->name('stores.posbridge.')->prefix('pos-bridge')->middleware('feature:pos_bridge')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/connect', 'connect')->name('connect');
    Route::post('/disconnect/{provider}', 'disconnect')->name('disconnect');
    Route::post('/mapping', 'saveMapping')->name('mapping');
    // M5: re-dispatch a failed POS push (tenant-scoped; idempotent per (order_id, provider)).
    Route::post('/retry/{push}', 'retryPush')->name('retry');
});

// N1 Foodics two-way POS: OAuth connect + dry-run import wizard + push log.
Route::controller('\Juwri\Modules\Channels\Http\Controllers\User\FoodicsController')->name('stores.foodics.')->prefix('pos-bridge/foodics')->middleware('feature:pos_bridge')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/connect', 'connect')->name('connect');
    Route::get('/callback', 'callback')->name('callback');
    Route::post('/import/preview', 'importPreview')->name('import.preview');
    Route::post('/import', 'import')->name('import');
    Route::post('/disconnect', 'disconnect')->name('disconnect');
});

// N7 Square OAuth connect (marketplace-grade; raw-token BC preserved).
Route::controller('\Juwri\Modules\Channels\Http\Controllers\User\SquareOAuthController')->name('stores.square.')->prefix('pos-bridge/square')->middleware('feature:pos_bridge')->group(function () {
    Route::post('/connect', 'connect')->name('connect');
    Route::get('/callback', 'callback')->name('callback');
    Route::post('/disconnect', 'disconnect')->name('disconnect');
});

// N4 Marn two-way POS: BYOK connect + dry-run import wizard + push log.
Route::controller('\Juwri\Modules\Channels\Http\Controllers\User\MarnController')->name('stores.marn.')->prefix('pos-bridge/marn')->middleware('feature:pos_bridge')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/connect', 'connect')->name('connect');
    Route::post('/import/preview', 'importPreview')->name('import.preview');
    Route::post('/import', 'import')->name('import');
    Route::post('/disconnect', 'disconnect')->name('disconnect');
});
