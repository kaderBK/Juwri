<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 |----------------------------------------------------------------------
 | Marketplace (Fusion) + Directory + Shopper public surface
 | (R2d — extracted from web.php)
 |----------------------------------------------------------------------
 | `require`d in-place from routes/web.php so it runs inside the SAME `web`
 | middleware group, controller namespace and variable scope, and keeps its
 | position BEFORE the storefront {slug} catch-all — route table byte-identical
 | (verified: `route:list` diff empty). Do NOT register separately in
 | RouteServiceProvider (that would change route order / {slug} precedence).
 */

use Illuminate\Support\Facades\Route;
use Juwri\Core\Support\ModuleRoutes;

// P1-13 13d: the Marketplace routes live in the module's slots (ee, absent without a licence); this file keeps their
// positions and the shopper help pillar (Content, Community).

/* DOCS-PLATFORM D3-7: brand-neutral shopper help pillar (docs/shoppers).
   Public and crawlable, no mk.gate — help must work even where the marketplace
   module is off. Slug pattern keeps README + non-kebab files unreachable. */
Route::get('fusion/help', '\Juwri\Modules\Content\Http\Controllers\HelpCenterController@shopperIndex')->middleware('csp.marketing')->name('mk.help.index'); // P6-122b
Route::get('fusion/help/search', '\Juwri\Modules\Content\Http\Controllers\HelpCenterController@search')->defaults('pillar', 'shoppers')->middleware('csp.marketing')->name('mk.help.search'); // D1-5
Route::get('fusion/help/{slug}.md', '\Juwri\Modules\Content\Http\Controllers\HelpCenterController@shopperRaw')->where('slug', '[a-z0-9-]+')->name('mk.help.raw'); // D2-1 raw .md
Route::get('fusion/help/{slug}', '\Juwri\Modules\Content\Http\Controllers\HelpCenterController@shopperShow')->where('slug', '[a-z0-9-]+')->middleware('csp.marketing')->name('mk.help.show');

ModuleRoutes::slot('Marketplace/marketplace-1');

ModuleRoutes::slot('Marketplace/marketplace-2');

ModuleRoutes::slot('Marketplace/marketplace-3');

ModuleRoutes::slot('Ordering/marketplace-1');
