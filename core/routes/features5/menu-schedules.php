<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
/*
 * PARITY-RUN T5 · time-based menus & pricing routes. Owned exclusively by the
 * T5 track — parallel agents must not edit another track's file. Auto-required
 * by the glob in routes/web.php, which runs BEFORE the {slug} storefront
 * catch-all, so `/schedules` resolves instead of being swallowed by publicView.
 *
 * There is exactly ONE zone here, and it is the owner dashboard, so
 * `feature:menu_schedules` middleware is the right gate: the caller is the
 * authenticated merchant, which is the subject that alias resolves.
 *
 * There is deliberately NO public route in this file. The customer-facing half
 * of T5 (the happy-hour price, the "not available right now" state) is rendered
 * inside the storefront pages that already exist, and its entitlement question
 * is asked store-scoped in-process via MenuScheduleService::enabled()
 * (FeatureAccess::storeAllows). Putting `feature:`/`entitled:` on a route an
 * anonymous diner reaches 403s every guest — the mistake this codebase has made
 * three times.
 */

use Juwri\Modules\Loyalty\Http\Controllers\User\PriceScheduleController;
use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Loyalty/features5-menu-schedules-1');
