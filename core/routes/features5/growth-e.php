<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
/*
 * HPMS lane E · growth / platform-gap dashboard routes. Owned exclusively by
 * lane E — parallel agents must not edit another lane's file. Auto-required by
 * the glob in routes/web.php, which runs BEFORE the {slug} storefront catch-all,
 * so these paths resolve instead of being swallowed by publicView.
 *
 * Every route here is the AUTHENTICATED owner dashboard. The middleware mirrors
 * the User dashboard group in web.php: `web` + `app.locale` (the OUTER
 * group's members — this file is required outside that wrapper, and omitting
 * app.locale breaks RTL/locale for the page — WV.V lesson) then the inner
 * `auth`/`staff.dashboard`/`dashboard.sync`/`reseller.suspension`/`abuse.hold`/
 * `csp.dashboard` list. New dashboard routes are OWNER-only by default (staff are
 * default-denied by EnsureStaffDashboardPermission until lane A maps them).
 */

use Juwri\Modules\Integrations\Http\Controllers\User\ApiTokenController;
use Juwri\Modules\Customers\Http\Controllers\User\CustomerMergeController;
use Juwri\Core\Http\Controllers\User\OwnershipTransferController;
use Juwri\Modules\Reservations\Http\Controllers\User\ReservationController;
use Juwri\Modules\Team\Http\Controllers\User\StaffController;
use Juwri\Modules\Storefront\Http\Controllers\User\StorefrontPageController;
use Juwri\Modules\Inventory\Http\Controllers\User\SupplierController;
use Illuminate\Support\Facades\Route;

$dashboard = ['web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'reseller.suspension', 'abuse.hold', 'csp.dashboard'];

/* E1 — reservation booking rules (clash buffer + blackout windows). Gated with
   feature:reservations to match the reservations screen these settings sit on. */
Route::middleware([...$dashboard, 'feature:reservations'])->group(function () {
    Route::post('reservations/settings', [ReservationController::class, 'saveSettings'])->name('reservations.settings');
});

/* E2 — ownership-transfer cooldown setting (per-store option). Same demo-guard as
   the other transfer writes. */
Route::middleware([...$dashboard, 'demo'])->group(function () {
    Route::post('transfer/settings', [OwnershipTransferController::class, 'saveSettings'])->name('transfer.settings');
});

/* E6 — inter-branch stock transfer: a chain tool, ee since 2026-10-05 (Chain or Enterprise licence). The slot is empty
   without it, so Community has no such route (a direct URL is a 404). Same middleware group as before. */
Route::middleware([...$dashboard, 'feature:inventory'])->group(function () {
    \Juwri\Core\Support\ModuleRoutes::slot('Inventory/ee-web-1');
});

/* E7 — supplier balance + payments (detail page + record-payment). */
Route::middleware([...$dashboard, 'feature:inventory'])->group(function () {
    Route::get('inventory/suppliers/{supplier}', [SupplierController::class, 'show'])->name('inventory.suppliers.show');
    Route::post('inventory/suppliers/{supplier}/payment', [SupplierController::class, 'recordPayment'])->name('inventory.suppliers.payment');
});

/* E9 — merge-duplicate-customers owner tool (same tier as the base customers
   list). Path is `customers-merge` (NOT `customers/merge`) so it isn't shadowed
   by the earlier-registered `customers/{customer}` resource route. */
Route::middleware($dashboard)->group(function () {
    Route::get('customers-merge', [CustomerMergeController::class, 'index'])->name('customers.merge.index');
    Route::post('customers-merge', [CustomerMergeController::class, 'merge'])->name('customers.merge');
});

/* E11 — API request-log viewer (owner /user/api screen tab). */
Route::middleware($dashboard)->group(function () {
    Route::get('api-request-logs', [ApiTokenController::class, 'requestLogs'])->name('api.requestLogs');
});

/* E12(b) — owner reset of a staff member's two-factor enrolment. */
Route::middleware([...$dashboard, 'demo'])->group(function () {
    Route::post('staff/{id}/reset-2fa', [StaffController::class, 'resetTwoFactor'])->whereNumber('id')->name('staff.reset2fa');
    // P1-19b: owner revokes one of a staff member's passkeys.
    Route::delete('staff/{id}/passkeys/{passkeyId}', [StaffController::class, 'revokePasskey'])->whereNumber('id')->whereNumber('passkeyId')->name('staff.passkeys.revoke');
});

/* E14(d) — owner editor for the extra storefront info pages (FAQ / refund / delivery). */
Route::middleware($dashboard)->group(function () {
    Route::get('storefront-pages', [StorefrontPageController::class, 'edit'])->name('storefront-pages.edit');
    Route::post('storefront-pages', [StorefrontPageController::class, 'save'])->name('storefront-pages.save');
});
