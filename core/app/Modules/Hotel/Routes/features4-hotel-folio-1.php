<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features4/hotel-folio.php (slot Hotel/features4-hotel-folio-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Hotel\Http\Controllers\User\HotelFolioController;

/* F7-7 (feature-platform gap fix): these are owner-dashboard routes behind
   `auth`, so the hotel module's plan-aware gate belongs here too — every other
   hotel group already carries it (routes/features4/hotel.php). `entitled:hotel`
   resolves the dashboard owner, which is always present on an authed route;
   never attach it to a group an anonymous caller can reach. The controllers
   keep their own HotelService::enabled() re-checks (defense in depth). */
/* WI-H1: the money endpoints (check-in / settle / check-out / statement) carry the
   staff permission gate, exactly like the read-only stays group at the bottom of this
   file. Without it EnsureStaffDashboardPermission never runs and any staff role can
   post a folio settlement. The `hotel_folio` permission is mapped in that middleware's
   ROUTE_PERMISSION_MAP; an unmapped name is default-DENY, so the two edits ship together. */
/* STRICT actions keep `entitled:hotel` (EnsureEntitled → paywall on a downgraded
   plan): bringing in a NEW guest, posting a NEW manual charge, or editing/cancelling
   a stay all require a live hotel plan. */
Route::middleware(['app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard', 'entitled:hotel'])
    ->prefix('dashboard/hotel/folio')
    ->name('hotel.folio.')
    ->group(function () {
        Route::post('check-in', [HotelFolioController::class, 'checkIn'])->name('checkin');
        /* A5: manual folio adjustment (signed charge/credit + reason). Same
           `hotel_folio` permission as settle — map hotel.folio.adjust in
           EnsureStaffDashboardPermission::ROUTE_PERMISSION_MAP (lane B) so staff
           can use it; unmapped it is owner-only (default-deny for staff). */
        Route::post('{stay}/adjust', [HotelFolioController::class, 'adjust'])->whereNumber('stay')->name('adjust');
        /* A11: stay lifecycle. `hotel.folio.stay.update` corrects guest name/phone
           (re-auths charge-to-room); `hotel.folio.stay.cancel` voids a mistaken stay
           at zero balance and frees the room. Same `hotel_folio` permission as settle —
           map both in EnsureStaffDashboardPermission (lane B) for staff access. */
        Route::post('{stay}/update', [HotelFolioController::class, 'stayUpdate'])->whereNumber('stay')->name('stay.update');
        Route::post('{stay}/cancel', [HotelFolioController::class, 'stayCancel'])->whereNumber('stay')->name('stay.cancel');
    });

/* WIND-DOWN drain actions DROP `entitled:hotel` on purpose: viewing a statement,
   recording a settlement, and checking a guest out must stay reachable after the
   owner turns hotel_mode off or the plan loses the entitlement mid-stay, so open
   folios can always be drained and closed (money would otherwise be stranded).
   HotelFolioController::hotelRestaurantForWindDown() re-gates each — enabled() OR
   canWindDown() (a stay still checked in) — so a store that never ran hotel still
   403s. The `hotel_folio` staff-permission gate (WI-H1) is unchanged. */
Route::middleware(['app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard'])
    ->prefix('dashboard/hotel/folio')
    ->name('hotel.folio.')
    ->group(function () {
        Route::get('{stay}/statement', [HotelFolioController::class, 'statement'])->whereNumber('stay')->name('statement');
        Route::post('{stay}/settle', [HotelFolioController::class, 'settle'])->whereNumber('stay')->name('settle');
        Route::post('{stay}/check-out', [HotelFolioController::class, 'checkOut'])->whereNumber('stay')->name('checkout');
    });

/* -------------------------------------------------------------------------
 | H7 — Stays & folio admin UI (owner dashboard). Mirrors the hotel.* nav group
 | (web, auth, staff.dashboard, dashboard.sync) so it sits alongside rooms /
 | requests / info. Each action re-checks HotelService::enabled() (H11 gating).
 * ---------------------------------------------------------------------- */
/* Wind-down drain (read side): the Stays list + statement + print stay reachable
   after a downgrade so the owner can find and close open folios. entitled:hotel
   dropped; hotelRestaurantForWindDown() re-gates (enabled OR a stay checked in). */
Route::middleware(['web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard'])
    ->prefix('hotel')
    ->name('hotel.')
    ->group(function () {
        Route::get('stays', [HotelFolioController::class, 'index'])->name('stays');
        Route::get('stays/{stay}', [HotelFolioController::class, 'show'])->whereNumber('stay')->name('stays.show');
        Route::get('stays/{stay}/print', [HotelFolioController::class, 'print'])->whereNumber('stay')->name('stays.print');
    });
