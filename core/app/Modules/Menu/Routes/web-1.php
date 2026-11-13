<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Menu/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// Reusable modifier library (global extras + variant-option groups)
Route::get('modifiers', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@index')->name('modifiers.index');
Route::post('modifiers/extras', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@storeExtra')->name('modifiers.extras.store');
Route::post('modifiers/extras/bulk', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@bulkStoreExtras')->name('modifiers.extras.bulkStore');
Route::post('modifiers/extras/ai-title', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@aiExtraTitle')->middleware('ratelimit:ai')->name('modifiers.extras.aiTitle');
// P1-17 (J27-B): AI item description draft for the item form (JSON; never saves the item).
Route::post('stores/{store}/menu/ai-describe', '\Juwri\Modules\Menu\Http\Controllers\User\ItemDescriptionController@draft')->middleware('ratelimit:ai')->name('stores.menu.aiDescribe');
Route::put('modifiers/extras/{extra}', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@updateExtra')->name('modifiers.extras.update');
Route::delete('modifiers/extras/{extra}', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@destroyExtra')->name('modifiers.extras.destroy');
Route::post('modifiers/groups', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@storeGroup')->name('modifiers.groups.store');
Route::put('modifiers/groups/{group}', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@updateGroup')->name('modifiers.groups.update');
Route::delete('modifiers/groups/{group}', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@destroyGroup')->name('modifiers.groups.destroy');
// Attach / detach library modifiers to a specific item
Route::post('menu/{menu}/attach-extra', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@attachExtra')->name('modifiers.attachExtra');
Route::delete('menu/{menu}/attach-extra/{extra}', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@detachExtra')->name('modifiers.detachExtra');
Route::post('menu/{menu}/attach-group', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@attachGroup')->name('modifiers.attachGroup');
Route::delete('menu/{menu}/attach-group/{group}', '\Juwri\Modules\Menu\Http\Controllers\User\ModifierController@detachGroup')->name('modifiers.detachGroup');
/* PARITY-RUN T6 — combo meals (superadmin-gated). Dashboard-only, so
   `feature:` middleware is legal here. The STOREFRONT half is
   deliberately ungated at the route layer and checks
   ComboService::enabled() in-controller: feature middleware on a route
   an anonymous diner reaches 403s every customer. */
Route::controller('\Juwri\Modules\Menu\Http\Controllers\User\ComboController')->name('combos.')->prefix('combos')->middleware('feature:combos')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'save')->name('save');
    Route::post('reorder', 'reorder')->name('reorder');
    Route::post('{combo}/toggle', 'toggle')->name('toggle');
    Route::delete('{combo}', 'destroy')->name('destroy');
});
