<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * BIOLINK-RUN (Lane B) · owner/staff dashboard for the Linktree-class bio page.
 * Owned exclusively by Lane B — parallel agents must not edit another lane's
 * route file. Auto-required by the glob in routes/web.php, which runs BEFORE the
 * {slug} storefront catch-all, so `/biolink` resolves to the builder instead of
 * being swallowed by publicView (the shadowing bug RouteShadowingTest freezes).
 *
 * Exactly ONE zone here — the authenticated owner dashboard — so
 * `feature:biolink` middleware (the superadmin kill-switch) is the correct gate:
 * the subject that alias resolves is the merchant. The customer-facing bio page
 * is a SEPARATE public route (Lane A, routes/web.php {slug}/bio cluster) whose
 * entitlement is asked store-scoped in-process via FeatureAccess::storeAllows —
 * NEVER `feature:` on a route an anonymous diner reaches.
 *
 * `demo` (IsDemoMode) sits on the group: it is a pass-through on GET and only
 * bites POST/PUT/DELETE, so it blocks builder/settings writes on the demo site
 * while leaving every read untouched.
 *
 * Staff access rides `staff.dashboard`: EnsureStaffDashboardPermission maps every
 * `biolink.*` route name to the `biolink` permission, so a staff member without
 * it gets 403 and the nav item is hidden (dashboard_allows('biolink')).
 */

use Juwri\Modules\Biolink\Http\Controllers\User\BioInsightsController;
use Juwri\Modules\Biolink\Http\Controllers\User\BioLinkController;
use Juwri\Modules\Biolink\Http\Controllers\User\BioTemplateController;
use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Biolink/features5-biolink-1');
