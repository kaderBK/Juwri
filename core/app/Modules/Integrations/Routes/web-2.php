<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Integrations/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// B4 Google Business ordering link (superadmin-gated).
Route::controller('\Juwri\Modules\Integrations\Http\Controllers\User\GbpController')->name('stores.gbp.')->prefix('google-ordering')->middleware('feature:gbp_link')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/connect', 'connect')->name('connect');
    Route::post('/set-link', 'setLink')->name('setLink');
    // google-partner Lane A — OAuth connect + disconnect (A2) + location pick (A4)
    // + menu sync (A5) — all additive in this Lane-A sub-block.
    Route::get('/oauth/connect', 'oauthConnect')->name('oauth.connect');
    Route::get('/oauth/callback', 'oauthCallback')->name('oauth.callback');
    Route::post('/disconnect', 'disconnect')->name('disconnect');
    Route::post('/location', 'selectLocation')->name('location');
    Route::post('/menu-sync', 'saveMenuSync')->name('menuSync');
    Route::post('/sync-now', 'syncNow')->name('syncNow');
});

/* ===== google-partner Lane B additive block — GBP reviews inbox + posts =====
   Owner-facing engagement surface for a connected GBP location. Inherits
   this group's namespace=User + auth/csp.dashboard middleware; gated by
   feature:gbp_link like the ordering link. Controllers use the B-owned
   GbpEngagement client (Fake until F0 wires real creds). Only Lane B edits
   this block. Reviews are Google reviews — never platform store_reviews. */
Route::controller('\Juwri\Modules\Integrations\Http\Controllers\User\GbpReviewsController')->name('stores.gbp.reviews.')->prefix('google-business/reviews')->middleware('feature:gbp_link')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/reply', 'reply')->name('reply');
    Route::delete('/reply', 'deleteReply')->name('deleteReply');
    // P1-17 (J27-B): AI reply draft (JSON, never posts); AI credits + ratelimit:ai like the other content AI tools.
    Route::post('/draft-reply', 'draftReply')->middleware('ratelimit:ai')->name('draftReply');
});
Route::controller('\Juwri\Modules\Integrations\Http\Controllers\User\GbpPostsController')->name('stores.gbp.posts.')->prefix('google-business/posts')->middleware('feature:gbp_link')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
});
