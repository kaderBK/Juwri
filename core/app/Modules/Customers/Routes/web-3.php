<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Customers/web-3); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* SYSTEMS-RUN S-P1: customer feedback inbox. Superadmin-gated.
               URI is `customer-feedback`, NOT `feedback`: the owner dashboard is
               bare at root, so a `feedback` prefix registered later silently
               replaced the PUBLIC marketing page GET /feedback (HomeController@
               feedback) and deleted the `feedback` route NAME, which killed the
               Route::has('feedback') CTAs in the bought theme (J9). All links here go
               through route('stores.feedback.*'), so the URI is free to move. */
Route::controller('\Juwri\Modules\Customers\Http\Controllers\User\FeedbackController')->name('stores.feedback.')->prefix('customer-feedback')->middleware('feature:support_desk')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('{id}/respond', 'respond')->name('respond');
    Route::post('{id}/close', 'close')->name('close');
});
