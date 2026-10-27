<?php

use Juwri\Core\Support\ModuleRoutes;

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

ModuleRoutes::slot('Verticals/verticals-lane-a-1');

Route::prefix('stores/{store}/brands')->name('retail.brands.')->controller('\Juwri\Modules\Menu\Http\Controllers\User\BrandController')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('/{brand}', 'update')->name('update');
    Route::delete('/{brand}', 'destroy')->name('destroy');
});

Route::prefix('stores/{store}/product-categories')->name('retail.categories.')->controller('\Juwri\Modules\Verticals\Http\Controllers\User\RetailCategoryController')->group(function () {
    Route::post('/', 'store')->name('store');
    Route::put('/{category}', 'update')->name('update');
    Route::delete('/{category}', 'destroy')->name('destroy');
});
