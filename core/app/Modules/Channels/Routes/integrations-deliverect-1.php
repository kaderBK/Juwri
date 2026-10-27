<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/integrations/deliverect.php (slot Channels/integrations-deliverect-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// A8: staff busy-mode + product snooze (Deliverect). Bearer StaffTokenAuth (the
// `api` group is CSRF-exempt for api/v1/*); resolve the store from the staff, gate
// on aggregator_deliverect in-controller. Names are api.v1.* so they self-document
// in the OpenAPI spec / discovery index.
Route::prefix('api/v1/staff/deliverect')
    ->middleware(['api', 'Juwri\Modules\Team\Http\Middleware\StaffTokenAuth', 'throttle:120,1,channels-integrations-deliverect-1'])
    ->name('api.v1.staff.deliverect.')
    ->group(function () {
        Route::get('status', [\Juwri\Modules\Channels\Http\Controllers\DeliverectStaffController::class, 'status'])->name('status');
        Route::post('busy', [\Juwri\Modules\Channels\Http\Controllers\DeliverectStaffController::class, 'busy'])->name('busy');
        Route::post('snooze', [\Juwri\Modules\Channels\Http\Controllers\DeliverectStaffController::class, 'snooze'])->name('snooze');
    });
