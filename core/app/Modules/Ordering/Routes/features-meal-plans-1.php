<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features/meal-plans.php (slot Ordering/features-meal-plans-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Ordering\Http\Controllers\Api\V1\MealPlanController;

// Two-segment prefix (`customer/meal-plan`) so GET index is not swallowed by
// the single-segment `{slug}` storefront catch-all. FQCN array actions avoid
// the root-namespace double-prefix trap.
Route::prefix('customer/meal-plan')
    ->middleware(['api', 'Juwri\Modules\Customers\Http\Middleware\CustomerTokenAuth', 'throttle:120,1,ordering-features-meal-plans-1'])
    ->name('meal-plan.')
    ->group(function () {
        Route::get('/',            [MealPlanController::class, 'index'])->name('index');
        Route::post('/',           [MealPlanController::class, 'store'])->name('store');
        Route::post('{id}/pause',  [MealPlanController::class, 'pause'])->name('pause');
        Route::post('{id}/resume', [MealPlanController::class, 'resume'])->name('resume');
        Route::post('{id}/skip',   [MealPlanController::class, 'skip'])->name('skip');
        Route::post('{id}/cancel', [MealPlanController::class, 'cancel'])->name('cancel');
    });
