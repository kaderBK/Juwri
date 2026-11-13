<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot AppStore/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* App Store catalog editor — listing commercials + storefront copy.
           (The review queue below is the approve/reject gate, not the listing.) */
        Route::controller('\Juwri\Modules\AppStore\Http\Controllers\Admin\AppCatalogController')->prefix('app-catalog')->name('app-catalog.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/{id}/edit', 'edit')->name('edit')->whereNumber('id');
            Route::post('/{id}', 'update')->name('update')->whereNumber('id');
            Route::post('/{id}/icon', 'uploadIcon')->name('icon')->whereNumber('id');
        });
