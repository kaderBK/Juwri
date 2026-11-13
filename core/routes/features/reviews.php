<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * theme-pack-blade · reviews feature routes.
 * Owned exclusively by its feature task — parallel agents must not edit
 * any other file under routes/. Registered by the glob in routes/web.php.
 *
 * F6 · Rich reviews (photos, distribution, filters, write-review).
 *  - GET  is public (anonymous browsing allowed).
 *  - POST accepts an OPTIONAL CustomerToken; a logged-in diner gets their review
 *    linked to their customer record and verified against delivered-order history.
 *    Anonymous diners are allowed and fall back to session_hash.
 */

use Juwri\Modules\Storefront\Http\Controllers\Features\MenuReviewController;
use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Storefront/features-reviews-1');
