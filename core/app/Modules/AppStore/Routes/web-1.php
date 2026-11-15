<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot AppStore/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* P4-4: marketplace theme review queue (approve/reject submitted versions) */
Route::controller('\Juwri\Modules\AppStore\Http\Controllers\Admin\ThemeReviewController')->group(function () {
    Route::get('/theme-reviews', 'index')->name('theme-reviews.index');
    Route::post('/theme-reviews/reorder', 'reorderListings')->name('theme-reviews.reorder');
    Route::post('/theme-reviews/{id}/approve', 'approve')->name('theme-reviews.approve');
    Route::post('/theme-reviews/{id}/reject', 'reject')->name('theme-reviews.reject');
    Route::post('/theme-reviews/{id}/gate', 'rerunGate')->name('theme-reviews.gate')->whereNumber('id');
    // stores P1-4: per-theme store listing (metadata + screenshots)
    Route::get('/theme-reviews/{theme}/listing', 'listing')->name('theme-reviews.listing');
    Route::post('/theme-reviews/{theme}/listing', 'saveListing')->name('theme-reviews.listing.save');
    Route::post('/theme-reviews/{theme}/media', 'uploadMedia')->middleware('ratelimit:upload')->name('theme-reviews.media.upload');
    Route::post('/theme-reviews/media/{media}/delete', 'deleteMedia')->name('theme-reviews.media.delete');
    Route::post('/theme-reviews/{theme}/media/move', 'moveMedia')->name('theme-reviews.media.move');
});

/* P6-243: refund a theme licence (buyer credited, seller share reversed or flagged) */
Route::post('/theme-purchases/{id}/refund', '\Juwri\Modules\AppStore\Http\Controllers\Admin\ThemePurchaseRefundController@refund')->whereNumber('id')->name('theme-purchases.refund');
