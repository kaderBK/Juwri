<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features5/social-login.php (slot Integrations/features5-social-login-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Integrations\Http\Controllers\Admin\SocialProvidersController;

Route::name('admin.')
    ->prefix(admin_url())
    ->middleware(['installed', 'checkUserIsBanned', 'localize', 'localizationRedirect', 'locale.remember', 'admin', 'staff.dashboard', 'admin.session', 'demo', 'csp.dashboard']) // SEC-W2 #6: same fence as the main admin group
    ->group(function () {
        Route::get('social-providers', [SocialProvidersController::class, 'index'])
            ->name('social-providers.index');
        Route::post('social-providers', [SocialProvidersController::class, 'update'])
            ->name('social-providers.update');
        Route::post('social-providers/test', [SocialProvidersController::class, 'test'])
            ->name('social-providers.test');
    });
