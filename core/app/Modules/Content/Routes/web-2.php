<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Content/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* HOME-PAGES HP-40: Site Pages block composer (admin-only; no new public routes). */
        Route::controller('\Juwri\Modules\Content\Http\Controllers\Admin\SitePageBuilderController')->prefix('site-pages')->name('site-pages.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/pages', 'storePage')->name('store');
            Route::get('/{id}/edit', 'edit')->name('edit');
            Route::put('/{id}/save', 'save')->name('save');
            Route::post('/{id}/publish', 'publish')->name('publish');
            Route::get('/{id}/preview', 'preview')->name('preview');
            Route::post('/{id}/translate', 'translate')->name('translate');
            Route::get('/{id}/revisions', 'revisions')->name('revisions');
            Route::post('/{id}/restore/{revisionId}', 'restore')->name('restore');
            Route::post('/upload', 'upload')->name('upload');
            Route::delete('/{id}', 'destroyPage')->name('destroy');
        });
