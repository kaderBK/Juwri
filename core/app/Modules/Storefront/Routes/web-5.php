<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Storefront/web-5); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* Theme Studio phase D4: generate marketplace themes with the AI theme builder
           against a vertical's demo store; `save` submits into the review queue above. */
        Route::controller('\Juwri\Modules\Storefront\Http\Controllers\Admin\ThemeStudioAdminController')->prefix('theme-studio')->name('theme-studio.')->group(function () {
            Route::get('/', 'page')->name('generate');
            Route::post('generate', 'generate')->middleware('ratelimit:ai')->name('run');
            Route::get('preview/{set}/{proposal}', 'preview')->name('preview');
            Route::post('save', 'save')->name('save');
        });

        /* Theme Studio phase E1: AI section author + review queue (superadmin only, dark
           behind feature:ai_section_author). Approval only marks the draft; code reaches
           the library via `php artisan studio:section-pr {id}` (a git PR), never from here. */
        Route::controller('\Juwri\Modules\Storefront\Http\Controllers\Admin\AiSectionAuthorController')->prefix('theme-studio/sections')->name('section-author.')
            ->middleware('feature:ai_section_author')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->middleware('ratelimit:ai')->name('store');
                Route::get('/{draft}', 'show')->whereNumber('draft')->name('show');
                Route::get('/{draft}/shot/{key}', 'screenshot')->whereNumber('draft')->where('key', '(en|ar)-(mobile|desktop)')->name('shot');
                Route::post('/{draft}/approve', 'approve')->whereNumber('draft')->name('approve');
                Route::post('/{draft}/reject', 'reject')->whereNumber('draft')->name('reject');
            });
