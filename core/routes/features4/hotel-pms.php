<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * HPMS — hotel booking-engine (PMS-lite) dashboard routes. Owned by lane A of
 * the hotel-pms run; parallel lanes never edit this file. Auto-required by the
 * glob in routes/web.php (outside any group), so the `web` middleware is listed
 * explicitly for session/CSRF.
 *
 * ALL routes are the STRICT tier: web + app.locale + auth +
 * staff.dashboard + dashboard.sync + entitled:hotel. A downgraded plan
 * paywalls/403s them (the PMS is a live-plan capability, unlike the folio
 * wind-down drain group). Each new route name is mapped in
 * EnsureStaffDashboardPermission::ROUTE_PERMISSION_MAP (unmapped = staff 403):
 *   bookings / calendar / availability / services.post / events.settle / checkin
 *     → hotel_folio
 *   room-types / rate-plans / rates / services CRUD / events CRUD / rooms.oo
 *     → hotel_admin
 *   housekeeping → hotel_requests
 * Controllers re-check HotelService::enabled() (defense in depth).
 */

use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Hotel/features4-hotel-pms-1');
