<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features4/ai-admin.php (slot Ai/features4-ai-admin-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Ai\Http\Controllers\Admin\AiProvidersController;

/*
 * A5 — Superadmin platform AI keys + models. Mirrors the admin route group in
 * web.php (name 'admin.', prefix admin_url(), middleware 'admin') so the page
 * lives under the superadmin panel. FQCN actions avoid namespace prefixing.
 */
Route::name('admin.')
    ->prefix(admin_url())
    ->middleware(['installed', 'checkUserIsBanned', 'localize', 'localizationRedirect', 'locale.remember', 'admin', 'demo', 'csp.dashboard'])
    ->group(function () {
        Route::get('ai-providers', [AiProvidersController::class, 'index'])
            ->name('ai-providers.index');
        Route::post('ai-providers', [AiProvidersController::class, 'update'])
            ->name('ai-providers.update');
        Route::post('ai-providers/test', [AiProvidersController::class, 'test'])
            ->name('ai-providers.test');
    });
