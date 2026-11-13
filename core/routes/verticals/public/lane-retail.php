<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Support\ModuleRoutes;

/**
 * P3-08 — retail PUBLIC storefront routes (wishlist, back-in-stock, size guide, returns, shipping quote).
 * require()d inside the storefront-localized group in routes/web.php, before the {slug} catch-all; every route has
 * more than one segment so it can never be taken for a store {slug}.
 */
ModuleRoutes::slot('Storefront/google-ordering-1'); // P5-20 G3: the Google Ordering Redirect deep link
ModuleRoutes::slot('Storefront/retail-public-1');
ModuleRoutes::slot('Verticals/verticals-public-retail-1');
