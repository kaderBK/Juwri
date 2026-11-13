<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features/follows.php (slot Platform/features-follows-1; Core since P1-13 13e); included in place by
// ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Controllers\Admin\StoreController;

// Superadmin: toggle a restaurant's "Official store ✓" verified badge.
Route::name('admin.')
    ->prefix(admin_url())
    ->middleware(['installed', 'checkUserIsBanned', 'localize', 'localizationRedirect', 'locale.remember', 'admin', 'csp.dashboard'])
    ->group(function () {
        Route::post('stores/{id}/verified', [StoreController::class, 'toggleVerified'])
            ->name('stores.verified.toggle');
    });
