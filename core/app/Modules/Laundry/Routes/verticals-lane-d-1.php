<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/verticals/lane-d.php (slot Laundry/verticals-lane-d-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/*
 * D3/D4: the service board (received→processing→ready→out_for_delivery→done)
 * + dashboard weight-capture on pickup. Reuses RetailOrderBoardService (Lane
 * B) unmodified; see LaundryServiceBoardController's docblock for why this is
 * its own page rather than an edit to Lane B's own board view.
 */
Route::prefix('services/board')->name('stores.service_board.')->controller('\Juwri\Modules\Laundry\Http\Controllers\User\LaundryServiceBoardController')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/{order}/advance', 'advance')->name('advance');
    Route::post('/{order}/items/{item}/weight', 'captureWeight')->name('weight');
});
