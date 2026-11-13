<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Customers/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* DIRECTORY L2 (T2-3): owner "Reviews" panel for store_reviews — read own
           approved reviews + reply once (editable within 24h). Moderation of
           store_reviews itself is superadmin-only (D3), so there is no approve/
           reject pair here, unlike the item-review routes just above. */
Route::controller('\Juwri\Modules\Customers\Http\Controllers\User\StoreReviewController')->group(function () {
    Route::get('stores/{store}/store-reviews', 'index')->name('dashboard.store-reviews');
    Route::post('stores/{store}/store-reviews/{review}/reply', 'reply')->name('dashboard.store-reviews.reply');
});
