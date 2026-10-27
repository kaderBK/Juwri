<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Content/web-4); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// CMS PAGES: Builder.js (html-mode) advanced editor for a page. Inherits the
        // admin group's `csp.dashboard` (allows 'unsafe-eval' + nonce for Builder.js).
        Route::controller('\Juwri\Modules\Content\Http\Controllers\Admin\PageController')->prefix('pages')->name('pages.')->group(function () {
            Route::get('{page}/builder', 'builder')->whereNumber('page')->name('builder');
            Route::post('{page}/builder/enable', 'enableBuilder')->whereNumber('page')->name('builder.enable');
            // Trusted raw-HTML mode (admin/superadmin only — this group is AdminMiddleware-gated).
            Route::post('{page}/builder/enable-trusted', 'enableTrusted')->whereNumber('page')->name('builder.enableTrusted');
            Route::post('{page}/builder/trusted-save', 'trustedSave')->whereNumber('page')->name('builder.trustedSave');
            Route::put('{page}/builder/save', 'builderSave')->whereNumber('page')->name('builder.save');
            Route::post('{page}/builder/publish', 'builderPublish')->whereNumber('page')->name('builder.publish');
            Route::get('{page}/builder/preview', 'builderPreview')->whereNumber('page')->name('builder.preview');
            Route::post('{page}/builder/template', 'builderTemplate')->whereNumber('page')->name('builder.template');
            Route::post('{page}/builder/assets', 'builderAsset')->whereNumber('page')->name('builder.assets');
            Route::post('{page}/builder/ai', 'builderAi')->whereNumber('page')->name('builder.ai');
        });
