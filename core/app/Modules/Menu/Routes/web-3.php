<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Menu/web-3); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* ===== google-suite Lane B (B3) additive block — menu JSON-LD toggle =====
               Own backend save path for the per-store `menu_seo_jsonld` opt-in (adds
               the schema.org MenuItem tree to the storefront JSON-LD). Inherits this
               group's `namespace=User` + auth/csp.dashboard middleware. The B3
               toggle-field partial posts to .save; Lane D only @includeIf's the
               partial. Nobody else edits this block. */
Route::get('jsonld-menu-toggle/{storeId}', '\Juwri\Modules\Menu\Http\Controllers\User\MenuJsonLdToggleController@show')->name('jsonld.menu.toggle.show');
Route::post('jsonld-menu-toggle/{storeId}', '\Juwri\Modules\Menu\Http\Controllers\User\MenuJsonLdToggleController@save')->name('jsonld.menu.toggle.save');
