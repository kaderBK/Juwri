<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P5-20 (ROADMAP 24 G3): the Google Ordering Redirect deep link — `{slug}/order/{type}` (and, on a store's own
// host, `order/{type}` via ResolveStoreHost). Included from routes/verticals/public/lane-retail.php inside the
// storefront-localized group, before the {slug} catch-all, so it inherits the locale prefix and the storefront
// middleware exactly like retail-public-1.php.
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Storefront\Http\Controllers\GoogleOrderingLinkController;

Route::get('{slug}/order/{type}', [GoogleOrderingLinkController::class, 'link'])
    ->name('storefront.googleOrdering.link')
    ->middleware('throttle:60,1,google-ordering-link');
