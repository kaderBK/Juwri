<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Support\Facades\Route;
use Juwri\Modules\LegacyCompat\Http\Controllers\LegacyMediaController;
use Juwri\Modules\LegacyCompat\Http\Controllers\LegacyRedirectController;

/*
| J52b legacy routes. Loaded ONLY when legacy_compat.enabled is true (see Support/LegacyCompatRoutes); every handler
| also re-checks the switch (404 when off), so a stale route cache cannot keep them alive.
| Rule (security review B2): no route without a real handler. These routes register BEFORE core, so a stub here
| would shadow a working core route with the same path.
|
| The `/{slug}?qr-id=&table=&src=&rt=` forms need no route: they arrive on the storefront `{slug}` page and are handled
| by Http/Middleware/LegacyStorefrontEntry (wired by the provider only while the switch is on).
| The signed-in customer forms `/restaurant/{id}/…` (loyalty, wallet JWT links, wallet balance/top-up, my-orders,
| notifications, refund request) are not in this file: Support/LegacyCustomerRoutes adds them right after the core
| customer routes, and only for the paths core no longer answers itself.
| OPEN: the remaining §3 forms (/{numericId} 301, /{locale}/{slug}, /r/{slug}/..., dashboard and API
| forms) come in the next LegacyCompat lanes (plan §6 M2); until a core route stops serving such a path (J52 rename),
| the core route keeps answering it.
*/

// /restaurant/{slug}/{slugTitle?} → 301 /{slug} (+ query). Marked fallback(): Laravel matches it only after every
// non-fallback route (also from a cached route table), so any core route with a path of this shape (the dashboard
// /restaurant/{id}/loyalty|floor-map|waitlist pages, the customer my-orders/notifications JSON, and core's own
// old-URL route while it exists) keeps winning. Same exclusion as core's route too, and one segment per parameter.
Route::get('restaurant/{slug}/{slugTitle?}', [LegacyRedirectController::class, 'storePage'])
    ->where('slug', '[^/]+')
    ->where('slugTitle', '^(?!(my-orders|notifications)$)[^/]*$')
    ->name('legacy_compat.store_page')
    ->fallback();

// /storage/{path} → 301 to the migrated media file (restaurant logo/cover, menu, image_menu, profile, testimonials,
// blog, media). Marked fallback(): a real core route or the web server's own file serving still wins.
Route::get('storage/{path}', [LegacyMediaController::class, 'show'])
    ->where('path', '(restaurant/(logo|cover)|menu|image_menu|profile|testimonials|blog|media)/.+')
    ->name('legacy_compat.media')
    ->fallback();
