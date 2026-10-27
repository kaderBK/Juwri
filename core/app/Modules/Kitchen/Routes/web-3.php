<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Kitchen/web-3); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// PARITY-RUN T2: PUBLIC order-status board (Preparing / Ready + daily tokens).
// MUST stay above the `{slug}` catch-all, or /board/pizza-hut resolves as a
// restaurant page and 404s (regression test: OrderBoardRouteOrderTest).
// Anonymous on purpose — NO feature:/entitled: middleware here; the gate is
// FeatureAccess::storeAllows() inside OrderBoardController.
// WV.V: + `app.locale`. This file's big localized group does not reach
// down here, so the board — a screen bolted to the wall of the dining room —
// rendered `lang="en" dir="ltr"` with English column headings inside an Arabic
// restaurant. Same middleware every other public storefront page runs.
Route::get('board/{slug}', '\Juwri\Modules\Kitchen\Http\Controllers\OrderBoardController@show')->name('board.show')->middleware(['csp.app', 'app.locale', 'throttle:public-check']);
Route::get('board/{slug}/state', '\Juwri\Modules\Kitchen\Http\Controllers\OrderBoardController@state')->name('board.state')->middleware('throttle:120,1,kitchen-board-state');
