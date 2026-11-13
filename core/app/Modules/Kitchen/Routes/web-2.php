<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Kitchen/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// B10 food safety — owner config, logs, labels (superadmin-gated).
Route::controller('\Juwri\Modules\Kitchen\Http\Controllers\User\FoodSafetyController')->name('stores.foodsafety.')->prefix('food-safety')->middleware('feature:food_safety')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/checks', 'storeCheck')->name('checks.store');
    Route::post('/checks/{id}/toggle', 'toggleCheck')->name('checks.toggle');
    Route::delete('/checks/{id}', 'destroyCheck')->name('checks.destroy');
    Route::get('/labels', 'labels')->name('labels');
});
