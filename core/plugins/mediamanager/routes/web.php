<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Support\Facades\Route;
use Plugins\mediamanager\app\Http\Controllers\Admin\MediaManagerController;

Route::group([
    'prefix' => admin_url(),
    'middleware' => ['installed', 'checkUserIsBanned', 'localize', 'localizationRedirect', 'locale.remember', 'admin', 'demo', 'csp.dashboard'],
], function () {

    // Dashboard
    Route::get('plugins/mediamanager', [MediaManagerController::class, 'index'])->name('admin.mediamanager.index');

    // Orphans
    Route::get('plugins/mediamanager/orphans', [MediaManagerController::class, 'orphans'])->name('admin.mediamanager.orphans');

    // Used
    Route::get('plugins/mediamanager/used', [MediaManagerController::class, 'used'])->name('admin.mediamanager.used');

    // Unknown
    Route::get('plugins/mediamanager/unknown', [MediaManagerController::class, 'unknown'])->name('admin.mediamanager.unknown');

    // Duplicates
    Route::get('plugins/mediamanager/duplicates', [MediaManagerController::class, 'duplicates'])->name('admin.mediamanager.duplicates');

    // Logs
    Route::get('plugins/mediamanager/logs', [MediaManagerController::class, 'logs'])->name('admin.mediamanager.logs');

    // Image Info (AJAX)
    Route::get('plugins/mediamanager/image-info', [MediaManagerController::class, 'imageInfo'])->name('admin.mediamanager.imageInfo');

    // Toggle Protection
    Route::post('plugins/mediamanager/toggle-protect', [MediaManagerController::class, 'toggleProtect'])->name('admin.mediamanager.toggleProtect');

    // Delete Duplicate Group
    Route::post('plugins/mediamanager/delete-duplicate-group', [MediaManagerController::class, 'deleteDuplicateGroup'])->name('admin.mediamanager.deleteDuplicateGroup');

    // Trash
    Route::get('plugins/mediamanager/trash', [MediaManagerController::class, 'trash'])->name('admin.mediamanager.trash');
    Route::post('plugins/mediamanager/restore-from-trash', [MediaManagerController::class, 'restoreFromTrash'])->name('admin.mediamanager.restoreFromTrash');
    Route::post('plugins/mediamanager/empty-trash', [MediaManagerController::class, 'emptyTrash'])->name('admin.mediamanager.emptyTrash');

    // Scan AJAX
    Route::get('plugins/mediamanager/scan/init', [MediaManagerController::class, 'scanInit'])->name('admin.mediamanager.scan.init');
    Route::post('plugins/mediamanager/scan', [MediaManagerController::class, 'scan'])->name('admin.mediamanager.scan');
    Route::get('plugins/mediamanager/scan/complete', [MediaManagerController::class, 'scanComplete'])->name('admin.mediamanager.scan.complete');

    // Delete Orphans
    Route::post('plugins/mediamanager/delete-orphans', [MediaManagerController::class, 'deleteOrphans'])->name('admin.mediamanager.deleteOrphans');

    // Delete Unknown
    Route::post('plugins/mediamanager/delete-unknown', [MediaManagerController::class, 'deleteUnknown'])->name('admin.mediamanager.deleteUnknown');

    // Directories (AJAX)
    Route::get('plugins/mediamanager/directories', [MediaManagerController::class, 'directories'])->name('admin.mediamanager.directories');

    // Installation
    Route::get('plugins/mediamanager/install', [MediaManagerController::class, 'install'])->name('admin.mediamanager.install');
    Route::post('plugins/mediamanager/enable', [MediaManagerController::class, 'enable'])->name('admin.mediamanager.enable');
    Route::post('plugins/mediamanager/disable', [MediaManagerController::class, 'disable'])->name('admin.mediamanager.disable');

    // Settings
    Route::get('plugins/mediamanager/settings', [MediaManagerController::class, 'settings'])->name('admin.mediamanager.settings');
    Route::post('plugins/mediamanager/settings', [MediaManagerController::class, 'saveSettings'])->name('admin.mediamanager.saveSettings');

    // Asset serving
    Route::get('plugins/mediamanager/assets/{file}', [MediaManagerController::class, 'assets'])->name('admin.mediamanager.assets');
});
