<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features/reviews.php (slot Storefront/features-reviews-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Customers\Http\Middleware\OptionalCustomerToken;
use Juwri\Modules\Storefront\Http\Controllers\Features\MenuReviewController;

Route::get('/r/{store}/item/{menu}/reviews', [MenuReviewController::class, 'index'])
    ->name('features.reviews.index');

// OptionalCustomerToken binds app('api.customer') when a valid bearer token is
// present, but never rejects an anonymous request.
Route::post('/r/{store}/item/{menu}/reviews', [MenuReviewController::class, 'store'])
    ->middleware([OptionalCustomerToken::class, 'throttle:10,1,menu-review-store'])
    ->name('features.reviews.store');
