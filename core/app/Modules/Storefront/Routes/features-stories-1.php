<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features/stories.php (slot Storefront/features-stories-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Storefront\Http\Controllers\User\StoryController;

/* Owner admin CRUD — same auth stack as the rest of the owner dashboard.
   MONETIZATION B6: gated behind feature:stories (dark until V0). The public
   customer story-viewer below stays ungated — existing stories keep showing,
   only creating/managing them is the paid capability. */
Route::group([
    'middleware' => ['web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard', 'reseller.suspension', 'abuse.hold', 'feature:stories'],
], function () {
    Route::get('stories', [StoryController::class, 'index'])->name('stories.index');
    Route::post('stories', [StoryController::class, 'store'])->name('stories.store');
    Route::post('stories/reorder', [StoryController::class, 'reorder'])->name('stories.reorder');
    Route::put('stories/{story}', [StoryController::class, 'update'])->name('stories.update');
    Route::delete('stories/{story}', [StoryController::class, 'destroy'])->name('stories.destroy');
});

/* Public customer story-viewer data: active, in-window, sorted by `sort`. */
Route::get('r/{store}/stories', [StoryController::class, 'publicIndex'])
    ->middleware(['web', 'throttle:public-check'])
    ->name('stories.public');
