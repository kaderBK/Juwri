<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09 slot Core/web-2 (split from Platform/web-2 in P1-13: instance ops moved to Core; 13e: the rest of
// Platform/web-2, the privileged-accounts page, joined it at its old position); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// A1: privileged accounts + 2FA status + sign-in audit (step-up protected).
Route::get('/security/accounts', '\App\Http\Controllers\Admin\AdminSecurityController@accounts')->name('security.accounts');

// C2: backup health console — validity, off-box state, restore drill.
Route::get('/backup-health', '\Juwri\Core\Http\Controllers\Admin\BackupHealthController@index')->name('backup-health.index');
Route::post('/backup-health/restore-check', '\Juwri\Core\Http\Controllers\Admin\BackupHealthController@runRestoreCheck')->name('backup-health.restore-check');
// M7: run the media mirror on demand (queued — a first run moves ~800 MB).
Route::post('/backup-health/media-mirror', '\Juwri\Core\Http\Controllers\Admin\BackupHealthController@runMediaMirror')->name('backup-health.media-mirror');
// O6: failed jobs console
Route::get('/failed-jobs', '\Juwri\Core\Http\Controllers\Admin\FailedJobsAdminController@index')->name('failed-jobs.index');
Route::post('/failed-jobs/{uuid}/retry', '\Juwri\Core\Http\Controllers\Admin\FailedJobsAdminController@retry')->name('failed-jobs.retry');
Route::post('/failed-jobs/{uuid}/forget', '\Juwri\Core\Http\Controllers\Admin\FailedJobsAdminController@forget')->name('failed-jobs.forget');
Route::post('/failed-jobs/flush', '\Juwri\Core\Http\Controllers\Admin\FailedJobsAdminController@flush')->name('failed-jobs.flush');
// H7: release orphaned scheduler mutexes (a killed drain otherwise self-skips).
Route::post('/queue/clear-schedule-locks', '\Juwri\Core\Http\Controllers\Admin\FailedJobsAdminController@clearScheduleLocks')->name('queue.clear-schedule-locks');
