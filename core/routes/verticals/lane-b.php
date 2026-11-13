<?php

use Juwri\Core\Support\ModuleRoutes;

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

ModuleRoutes::slot('Ordering/verticals-lane-b-1');

// B5: retail/laundry order board (relabeled per vertical, never "kitchen" —
// KDS stays the food-only board, untouched by this controller).
Route::get('orders/board', '\Juwri\Modules\Verticals\Http\Controllers\User\RetailOrderBoardController@index')->name('stores.orders.board');
Route::post('orders/board/{order}/advance', '\Juwri\Modules\Verticals\Http\Controllers\User\RetailOrderBoardController@advance')->name('stores.orders.board.advance');

// B4: shipment/parcel — mark an order shipped with tracking + carrier.
Route::post('orders/{order}/shipment', '\Juwri\Modules\Delivery\Http\Controllers\User\ShipmentController@update')->name('stores.orders.shipment.update');
