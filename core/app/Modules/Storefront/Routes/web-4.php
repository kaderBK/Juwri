<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Storefront/web-4); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* Restaurant menu templates (store_templates) — admin overlay, fallback, bulk reassign */
Route::controller('\Juwri\Modules\Storefront\Http\Controllers\Admin\MenuTemplateController')->group(function () {
    Route::get('/menu-templates', 'index')->name('menu-templates.index');
    Route::get('/menu-templates/stores', 'stores')->name('menu-templates.stores');
    Route::post('/menu-templates/stores/switch', 'switchStore')->name('menu-templates.stores.switch');
    Route::get('/menu-templates/segments', 'segments')->name('menu-templates.segments');
    Route::post('/menu-templates/segments/assign', 'segmentAssign')->name('menu-templates.segments.assign');
    Route::get('/menu-templates/rollouts', 'rollouts')->name('menu-templates.rollouts');
    Route::post('/menu-templates/rollouts/start', 'startRollout')->name('menu-templates.rollouts.start');
    Route::post('/menu-templates/rollouts/{rollout}/stop', 'stopRollout')->name('menu-templates.rollouts.stop')->whereNumber('rollout');
    Route::post('/menu-templates/fallback', 'fallback')->name('menu-templates.fallback');
    Route::post('/menu-templates/default', 'defaultTemplate')->name('menu-templates.default');
    Route::post('/menu-templates/stories-entry-default', 'storiesEntryDefault')->name('menuTemplates.storiesEntryDefault');
    Route::post('/menu-templates/reassign', 'reassign')->name('menu-templates.reassign');
    Route::post('/menu-templates/bulk-reassign', 'bulkReassign')->name('menu-templates.bulk-reassign');
    Route::post('/menu-templates/migrations/{migration}/undo', 'undoMigration')->name('menu-templates.migrations.undo')->whereNumber('migration');
    Route::post('/menu-templates/bulk', 'bulk')->name('menu-templates.bulk');
    Route::get('/menu-templates/{slug}/edit', 'edit')->name('menu-templates.edit')->where('slug', '[A-Za-z0-9_-]+');
    Route::get('/menu-templates/{slug}/restaurants', 'templateStores')->name('menu-templates.restaurants')->where('slug', '[A-Za-z0-9_-]+');
    Route::post('/menu-templates/{slug}/toggle', 'toggle')->name('menu-templates.toggle')->where('slug', '[A-Za-z0-9_-]+');
    Route::post('/menu-templates/{slug}/sort', 'sort')->name('menu-templates.sort')->where('slug', '[A-Za-z0-9_-]+');
    Route::post('/menu-templates/{slug}', 'update')->name('menu-templates.update')->where('slug', '[A-Za-z0-9_-]+');
});
