<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Biolink/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Middleware\AddCspHeaders;

// BIOLINK-RUN A4: public link-in-bio page. Two-segment, so it resolves BEFORE
    // the {slug} catch-all below (and needs no ReservedSlug — 'bio' is not a
    // top-level path, exactly like {slug}/unsubscribe). Registered in the SAME
    // localized group as the catch-all so it works in both locale modes
    // (`/{slug}/bio` in session mode, `/{locale}/{slug}/bio` in prefix mode).
    // NO `feature:` middleware — the storefront gate is asked in-controller via
    // FeatureAccess::storeAllows; a disabled/dark store 302s to the menu.
    Route::get('{slug}/bio', '\Juwri\Modules\Biolink\Http\Controllers\User\BioPublicController@show')->name('bio.public')->middleware(AddCspHeaders::class);
