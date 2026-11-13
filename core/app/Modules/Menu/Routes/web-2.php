<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Menu/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// FEATURES-5 Track B: public media library sources (curated pool,
            // Pexels/Unsplash stock, Google image search, AI-generated). Every
            // "use" copies the file into the owner's own MediaAsset (quota-checked).
            // MONETIZATION B8: basic media (upload/list/delete above) stays FREE —
            // it is required for a basic menu (DECISIONS non-gate). The enhanced
            // sources (stock, public pool, AI generation) are the paid Pro tier,
            // `feature:media_library_pro`. Dark until V0.
            Route::controller('\Juwri\Modules\Menu\Http\Controllers\User\PublicMediaBrowseController')->prefix('media/sources')->name('media.sources.')->middleware('feature:media_library_pro')->group(function () {
                Route::get('providers', 'providers')->name('providers');
                Route::get('public', 'publicList')->middleware('throttle:search')->name('public');
                Route::post('public/copy', 'copyPublic')->name('public.copy');
                Route::get('stock/search', 'stockSearch')->name('stock.search');
                Route::post('stock/copy', 'copyExternal')->name('stock.copy');
                Route::post('ai/generate', 'aiGenerate')->middleware('ratelimit:ai')->name('ai.generate');
                Route::post('ai/save', 'aiSave')->name('ai.save');
                Route::post('settings', 'saveSettings')->name('settings');
            });
