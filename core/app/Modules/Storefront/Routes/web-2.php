<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Storefront/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// Storefront promo / hero banners
            // MONETIZATION B6: gated behind feature:banners (dark until V0).
            Route::middleware('feature:banners')->group(function () {
                Route::get('banners', '\Juwri\Modules\Storefront\Http\Controllers\User\BannerController@index')->name('banners.index');
                Route::post('banners', '\Juwri\Modules\Storefront\Http\Controllers\User\BannerController@store')->name('banners.store');
                Route::post('banners/reorder', '\Juwri\Modules\Storefront\Http\Controllers\User\BannerController@reorder')->name('banners.reorder');
                Route::put('banners/{banner}', '\Juwri\Modules\Storefront\Http\Controllers\User\BannerController@update')->name('banners.update');
                Route::delete('banners/{banner}', '\Juwri\Modules\Storefront\Http\Controllers\User\BannerController@destroy')->name('banners.destroy');
            }); // end feature:banners group
