<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features5/table-otp.php (slot Pos/features5-table-otp-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Pos\Http\Controllers\Staff\PosController;

/* -------------------------------------------------------------------------
 | POS approval queue (verification mode `staff_approve`).
 | Same `auth:staff,web` guard the rest of the register uses — the actor,
 | restaurant and permissions are resolved inside PosController::context(),
 | which also enforces the pos_enabled plan feature.
 * ---------------------------------------------------------------------- */
Route::middleware(['web', 'auth:staff,web'])->group(function () {
    Route::get('pos/orders/approvals', [PosController::class, 'pendingApprovals'])->name('pos.orders.approvals');
    Route::post('pos/orders/{id}/approve', [PosController::class, 'approveOrder'])->whereNumber('id')->name('pos.orders.approve');
    Route::post('pos/orders/{id}/reject', [PosController::class, 'rejectOrder'])->whereNumber('id')->name('pos.orders.reject');
});
