<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Media/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* Operations: webhook + AI usage observability */
        /* FEATURES-5 Track B1: curated public media library (superadmin) */
        Route::controller('\Juwri\Modules\Media\Http\Controllers\Admin\PublicMediaController')->prefix('public-media')->name('public-media.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->middleware('ratelimit:upload')->name('store');
            Route::put('/{id}', 'update')->name('update');
            Route::post('/{id}/toggle', 'toggle')->name('toggle');
            Route::delete('/{id}', 'destroy')->name('destroy');
        });

        /* FEATURES-5 Track B2/B3: platform-default stock + media-search keys */
        Route::get('/media-sources', '\Juwri\Modules\Media\Http\Controllers\Admin\MediaSourceSettingsController@index')->name('media-sources.index');
        Route::post('/media-sources', '\Juwri\Modules\Media\Http\Controllers\Admin\MediaSourceSettingsController@update')->name('media-sources.update');
