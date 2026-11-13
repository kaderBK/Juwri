<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/apiv1.php (slot Integrations/apiv1-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Integrations\Services\Api\OpenApiGenerator;

// ═══════════════════════════════════════════════════════════════════════════
// #99: REST API v1
// ═══════════════════════════════════════════════════════════════════════════

// Public discovery/root endpoint so the documented Base URL returns a useful
// response instead of a 404. No auth required.
// A3: discovery index built from the live router (was a hand-maintained array that had
// drifted). New api.v1.* endpoints self-document. Cached; busted on deploy/route:cache.
Route::get('api/v1', function () {
    $endpoints = Cache::rememberForever(
        'api.v1.index',
        fn () => OpenApiGenerator::discoveryIndex()
    );

    return response()->json([
        'name' => brand_title().' API', // RP4: reseller brand on a reseller host
        'version' => 'v1',
        'base_url' => url('/api/v1'),
        'authentication' => 'Send your token as: Authorization: Bearer <token>',
        'docs' => url('/developers'),
        'openapi' => url('/api/v1/openapi.json'),
        'endpoints' => $endpoints,
    ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
})->name('api.v1.index');

// A3: OpenAPI 3.1 document, generated from the router (always in parity — see
// OpenApiParityTest). Registered BEFORE the storefront {slug} catch-all.
// TODO(rev/mv-surface-api P5): OpenApiGenerator (app/Modules/Integrations/Services/Api/OpenApiGenerator.php,
// outside this pass's edit fence) builds paths/methods/operationIds purely from
// Route::getRoutes() — the new brands/variants/shipments/fitments routes and the
// widened `orders.store`/`storefront.orders.create` request already appear
// automatically, verified via OpenApiParityTest's route/spec set-equality. It does
// NOT encode per-field schemas today (no enum for `type`, no response body shape),
// so there is nothing stale to regenerate for THIS change — but the day it grows
// field-level schemas, `type` needs the FulfillmentRegistry-driven enum (no longer
// the fixed on-table/takeaway/delivery literal) and StoreController's response
// needs `store_type`/`vertical` documented. Do not hand-edit the generated spec.
Route::get('api/v1/openapi.json', function () {
    $spec = Cache::rememberForever(
        'api.v1.openapi',
        fn () => OpenApiGenerator::spec()
    );

    return response()->json($spec, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
})->name('api.v1.openapi');
