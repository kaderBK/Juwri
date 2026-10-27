<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * rev/multi-vertical F0-7 — Lane C DASHBOARD routes.
 *
 * This file is require()d INSIDE the User dashboard group in routes/web.php, so it
 * already inherits: namespace App\Http\Controllers\User, and middleware
 * ['auth','staff.dashboard','dashboard.sync','reseller.suspension','abuse.hold','csp.dashboard'].
 *
 * Add ONLY Lane C's authed dashboard routes here. Never edit web.php or another
 * lane's file (conflict-free parallel merges). Controller strings resolve under
 * the User namespace (e.g. '\Juwri\Modules\Menu\Http\Controllers\User\BrandController@index').
 */

// Lane C dashboard routes go here.
