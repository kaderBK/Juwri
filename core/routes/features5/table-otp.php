<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
/*
 * PARITY-RUN T3 · verified table QR routes. Owned exclusively by the T3 track —
 * parallel agents must not edit another track's file. Auto-required by the glob
 * in routes/web.php, which runs BEFORE the {slug} storefront catch-all, so every
 * path declared here resolves instead of being swallowed by publicView.
 *
 * Two zones with deliberately different gating:
 *
 *   Owner dashboard  → `feature:table_otp`. The caller is the authenticated
 *                      merchant, which is exactly the subject that middleware
 *                      resolves; a kill-switched platform hides the screen.
 *
 *   Public guest     → NO feature/entitled middleware, ever. These are reached
 *                      by anonymous diners, where those aliases resolve the
 *                      subject from the session and 403 everybody. The
 *                      entitlement question is asked store-scoped inside
 *                      TableSessionService::mode() (FeatureAccess::storeAllows),
 *                      and a store that is not entitled simply has nothing to
 *                      verify. This exact mistake has bitten the codebase 3×.
 */

use Juwri\Modules\Pos\Http\Controllers\Staff\PosController;
use Juwri\Modules\Tables\Http\Controllers\TableVerifyController;
use Juwri\Modules\Tables\Http\Controllers\User\TableVerificationController;
use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Pos/features5-table-otp-1');

\Juwri\Core\Support\ModuleRoutes::slot('Tables/features5-table-otp-1');
