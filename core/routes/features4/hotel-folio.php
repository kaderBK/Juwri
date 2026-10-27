<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * features-run-4 · hotel-folio track routes (H6 — charge-to-room folio).
 * Owned exclusively by its track's agent — parallel agents must not edit
 * routes/web.php or another track's file. Auto-required by the glob in
 * routes/web.php. Use FQCN array actions to avoid namespace prefixing.
 *
 * Owner-authed (dashboard.sync scopes to the current store; the controller
 * further requires hotel_mode). No guest-facing routes here — the guest
 * charge-to-room tender is H8; posting an order to a folio runs in-process via
 * HotelFolioService::chargeOrder from the order flow.
 */

use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Hotel/features4-hotel-folio-1');
