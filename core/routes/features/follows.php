<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * theme-pack-blade · follows feature routes.
 * Owned exclusively by its feature task — parallel agents must not edit
 * any other file under routes/. Registered by the glob in routes/web.php.
 *
 * F2 · Follow / subscribe-to-brand.
 *  - Customer follow/unfollow: authenticated by CustomerTokenAuth (a logged-in
 *    customer only). Logged-out is handled in the theme UI (login sheet).
 *  - Superadmin verified-badge toggle: behind the `admin` middleware, so an owner
 *    can never self-verify.
 */

use Illuminate\Support\Facades\Route;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerFollowController;
use Juwri\Modules\Customers\Http\Middleware\CustomerTokenAuth;

Route::middleware([CustomerTokenAuth::class, 'throttle:60,1,core-follows'])
    ->group(function () {
        Route::post('r/{store}/follow', [CustomerFollowController::class, 'store'])
            ->name('customer.follow');
        Route::delete('r/{store}/follow', [CustomerFollowController::class, 'destroy'])
            ->name('customer.unfollow');
    });

ModuleRoutes::slot('Core/features-follows-1');
