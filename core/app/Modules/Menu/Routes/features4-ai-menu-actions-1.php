<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features4/ai-menu-actions.php (slot Menu/features4-ai-menu-actions-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Menu\Http\Controllers\User\AiMenuActionController;

Route::middleware(['web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard', 'feature:ai_assistant'])
    ->prefix('ai/menu-actions')
    ->name('ai.')
    ->group(function () {
        Route::get('/', [AiMenuActionController::class, 'index'])->name('menu-actions');
        Route::post('propose', [AiMenuActionController::class, 'propose'])->name('menu-actions.propose');
        Route::post('{action}/act', [AiMenuActionController::class, 'act'])->whereNumber('action')->name('menu-actions.act');
    });
