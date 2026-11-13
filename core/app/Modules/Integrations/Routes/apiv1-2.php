<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/apiv1.php (slot Integrations/apiv1-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// App-token routes. ApiTokenAuth:any authenticates; EnsureOwnerAppToken then
// admits ONLY kind=app tokens, so an integration token can never be exchanged
// for a dashboard session.
Route::prefix('api/v1/app')
    ->namespace('Api\V1')
    ->middleware([
        'api', 'throttle:owner-api', 'Juwri\Modules\Integrations\Http\Middleware\LogApiRequest',
        'App\Http\Middleware\ApiTokenAuth:any', 'App\Http\Middleware\EnsureOwnerAppToken',
    ])
    ->name('api.v1.app.')
    ->group(function () {
        Route::get('me', 'OwnerAppController@me')->name('me');
        Route::post('session', 'OwnerAppController@session')->name('session');
        Route::post('devices', 'OwnerAppController@registerDevice')->name('devices.register');
        Route::post('devices/unregister', 'OwnerAppController@unregisterDevice')->name('devices.unregister');
        Route::post('auth/logout', 'OwnerAppController@logout')->name('logout');
    });

// Owner-token API (merchant + delivery management). Each route declares the
// granular ability it needs (resource:action). A legacy `['read']` token still
// covers every `:read` route and `['write']` covers everything, so scoped tokens
// are purely additive — see Juwri\Modules\Integrations\Models\ApiToken::hasAbility().
Route::prefix('api/v1')
    ->namespace('Api\V1')
    ->middleware(['api', 'throttle:owner-api', 'Juwri\Modules\Integrations\Http\Middleware\LogApiRequest']) // A1: per-token budget; HPMS E11: request log
    ->name('api.v1.')
    ->group(function () {
        $scope = fn ($ability) => 'App\Http\Middleware\ApiTokenAuth:'.$ability;

        // F0-3 (integrations pack): identity endpoint. `any` = readable by any
        // valid token, because a Zapier/Make/n8n connection minted with only
        // webhooks:write uses this as its auth-test + connection label.
        Route::get('me', 'MeController@show')->middleware($scope('any'))->name('me');

        // Restaurants + menu (read)
        Route::get('stores', 'StoreController@index')->middleware($scope('restaurants:read'))->name('stores.index');
        Route::get('stores/{id}', 'StoreController@show')->middleware($scope('restaurants:read'))->name('stores.show');
        Route::get('stores/{id}/menu', 'StoreController@menu')->middleware($scope('menu:read'))->name('stores.menu');

        // Menu management (menu:write)
        Route::post('stores/{id}/menu/categories', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\MenuAdminController@storeCategory')->middleware($scope('menu:write'))->name('menu.categories.store');
        Route::put('stores/{id}/menu/categories/{categoryId}', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\MenuAdminController@updateCategory')->middleware($scope('menu:write'))->name('menu.categories.update');
        Route::delete('stores/{id}/menu/categories/{categoryId}', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\MenuAdminController@deleteCategory')->middleware($scope('menu:write'))->name('menu.categories.delete');
        Route::post('stores/{id}/menu/items', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\MenuAdminController@storeItem')->middleware($scope('menu:write'))->name('menu.items.store');
        Route::put('stores/{id}/menu/items/{itemId}', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\MenuAdminController@updateItem')->middleware($scope('menu:write'))->name('menu.items.update');
        Route::delete('stores/{id}/menu/items/{itemId}', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\MenuAdminController@deleteItem')->middleware($scope('menu:write'))->name('menu.items.delete');
        Route::patch('stores/{id}/menu/items/{itemId}/sold-out', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\MenuAdminController@toggleSoldOut')->middleware($scope('menu:write'))->name('menu.items.soldout');

        // Orders (orders:read / orders:write)
        Route::get('stores/{id}/orders', 'StoreController@orders')->middleware($scope('orders:read'))->name('stores.orders');
        Route::post('stores/{id}/orders', 'OrderAdminController@store')->middleware($scope('orders:write'))->name('orders.store');
        Route::get('stores/{id}/orders/{orderId}', 'OrderAdminController@show')->middleware($scope('orders:read'))->name('orders.show');
        Route::put('stores/{id}/orders/{orderId}/status', 'StoreController@updateOrderStatus')->middleware($scope('orders:write'))->name('stores.orders.status');

        // F0-4 (integrations pack): reservations list — the polling source for a
        // "New Reservation" trigger. `feature:reservations` declared AFTER
        // ApiTokenAuth so the entitlement subject is the token owner (JSON 403,
        // never a redirect), mirroring the crm_suite gate on the customers list.
        Route::get('stores/{id}/reservations', 'StoreController@reservations')->middleware([$scope('orders:read'), 'feature:reservations'])->name('stores.reservations');

        /* rev/mv-surface-api P2 (mcp-fusion): multi-vertical resources — READ
           ONLY. Brands (retail/cosmetic/clothing/carparts/pharmacy), product
           variants (the size×color matrix + sku/stock/barcode), shipments
           (physical-goods parcel tracking) and vehicle fitments (car-parts YMM
           compatibility). Same `ability` + `feature:multi_vertical` pairing as
           the MCP server's list_brands/list_variants/list_shipments/
           list_fitments tools (OwnerTools) — a token already
           scoped for those (menu:read / orders:read) reaches the identical data
           over plain REST, no new scope to mint. `feature:multi_vertical` is
           declared AFTER ApiTokenAuth so the entitlement subject is the TOKEN
           OWNER and a non-entitled owner gets a JSON 403, never a redirect. A
           store outside these verticals (a restaurant) just gets an empty list
           back — the entitlement gate is the only door, no separate
           vertical-shape check. */
        $multiVertical = 'feature:multi_vertical';
        Route::get('stores/{id}/brands', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\BrandApiController@index')->middleware([$scope('menu:read'), $multiVertical])->name('brands.index');
        Route::get('stores/{id}/brands/{brandId}', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\BrandApiController@show')->middleware([$scope('menu:read'), $multiVertical])->name('brands.show');
        Route::get('stores/{id}/menu/items/{itemId}/variants', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\VariantApiController@index')->middleware([$scope('menu:read'), $multiVertical])->name('variants.index');
        Route::get('stores/{id}/menu/items/{itemId}/variants/{variantId}', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\VariantApiController@show')->middleware([$scope('menu:read'), $multiVertical])->name('variants.show');
        // rev/mv-surface-api: variant WRITE (menu:write + feature:multi_vertical) — build a
        // goods item's size×color matrix with per-variant sku/stock/barcode over the API.
        Route::post('stores/{id}/menu/items/{itemId}/variants', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\VariantApiController@store')->middleware([$scope('menu:write'), $multiVertical])->name('variants.store');
        Route::put('stores/{id}/menu/items/{itemId}/variants/{variantId}', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\VariantApiController@update')->middleware([$scope('menu:write'), $multiVertical])->name('variants.update');
        Route::delete('stores/{id}/menu/items/{itemId}/variants/{variantId}', '\Juwri\Modules\Menu\Http\Controllers\Api\V1\VariantApiController@destroy')->middleware([$scope('menu:write'), $multiVertical])->name('variants.destroy');
        Route::get('stores/{id}/shipments', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\ShipmentApiController@index')->middleware([$scope('orders:read'), $multiVertical])->name('shipments.index');
        Route::get('stores/{id}/orders/{orderId}/shipment', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\ShipmentApiController@show')->middleware([$scope('orders:read'), $multiVertical])->name('shipments.show');
        Route::get('stores/{id}/fitments', '\Juwri\Modules\Verticals\Http\Controllers\Api\V1\FitmentApiController@index')->middleware([$scope('menu:read'), $multiVertical])->name('fitments.index');
        Route::get('stores/{id}/fitments/{fitmentId}', '\Juwri\Modules\Verticals\Http\Controllers\Api\V1\FitmentApiController@show')->middleware([$scope('menu:read'), $multiVertical])->name('fitments.show');

        /* Customers + analytics (read).
           FIX-6 F6-1 (gap audit): customer PII was reachable with a bare
           `customers:read` token while the web CRM sits behind
           `feature:crm_suite` (see the crm.* group above). Declared AFTER
           ApiTokenAuth so the entitlement subject is the TOKEN OWNER, and the
           refusal is a JSON 403 — never a redirect, never a partial list. */
        Route::get('stores/{id}/customers', 'CustomerController@index')->middleware([$scope('customers:read'), 'feature:crm_suite'])->name('customers.index');
        // B4-a (integrations pack): create a customer (Zapier/Make/n8n "Create
        // Customer" action). Same crm_suite gate as the list, customers:write.
        Route::post('stores/{id}/customers', 'CustomerController@store')->middleware([$scope('customers:write'), 'feature:crm_suite'])->name('customers.store');
        // K6: read-only guest CDP — profiles + RFM segment, and segment counts.
        Route::get('stores/{id}/guests', '\Juwri\Modules\Ordering\Http\Controllers\Api\V1\GuestController@index')->middleware([$scope('customers:read'), 'feature:crm_suite'])->name('guests.index');
        Route::get('stores/{id}/guests/segments', '\Juwri\Modules\Ordering\Http\Controllers\Api\V1\GuestController@segments')->middleware([$scope('customers:read'), 'feature:crm_suite'])->name('guests.segments');
        Route::get('stores/{id}/analytics', 'AnalyticsController@summary')->middleware($scope('analytics:read'))->name('analytics.summary');

        /* Drivers (drivers:read / drivers:write) + delivery lifecycle (orders:write).
           FIX-1 F1-1 (gap audit): token abilities alone were the ONLY gate here, so a
           non-entitled owner could mint a token and drive the whole dispatch suite
           through the API while the web equivalent (stores/dispatch/map|assign|
           report + the driver PWA shell) requires `feature:driver_app`. The alias is
           declared AFTER ApiTokenAuth so the entitlement subject is the token's owner;
           on an API request it answers with a JSON 403, never a redirect. */
        $driverApp = 'feature:driver_app';
        Route::get('drivers', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAdminController@index')->middleware([$scope('drivers:read'), $driverApp])->name('drivers.index');
        Route::post('drivers', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAdminController@store')->middleware([$scope('drivers:write'), $driverApp])->name('drivers.store');
        Route::put('drivers/{id}', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAdminController@update')->middleware([$scope('drivers:write'), $driverApp])->name('drivers.update');
        Route::delete('drivers/{id}', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAdminController@destroy')->middleware([$scope('drivers:write'), $driverApp])->name('drivers.destroy');
        Route::post('drivers/{id}/rotate-token', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAdminController@rotateToken')->middleware([$scope('drivers:write'), $driverApp])->name('drivers.rotate');
        Route::get('stores/{id}/deliveries', 'OrderAdminController@deliveries')->middleware([$scope('orders:read'), $driverApp])->name('deliveries.index');
        Route::post('stores/{id}/orders/{orderId}/assign', 'OrderAdminController@assignDriver')->middleware([$scope('orders:write'), $driverApp])->name('orders.assign');
        Route::put('stores/{id}/orders/{orderId}/delivery-status', 'OrderAdminController@updateDeliveryStatus')->middleware([$scope('orders:write'), $driverApp])->name('orders.delivery');

        // A5-6: programmatic webhook-endpoint management (Zapier/Make REST-Hooks
        // subscribe here). webhooks:read to list, webhooks:write to create/delete.
        Route::get('webhook-endpoints', '\Juwri\Modules\Integrations\Http\Controllers\Api\V1\WebhookEndpointController@index')->middleware($scope('webhooks:read'))->name('webhooks.index');
        Route::post('webhook-endpoints', '\Juwri\Modules\Integrations\Http\Controllers\Api\V1\WebhookEndpointController@store')->middleware($scope('webhooks:write'))->name('webhooks.store');
        Route::delete('webhook-endpoints/{id}', '\Juwri\Modules\Integrations\Http\Controllers\Api\V1\WebhookEndpointController@destroy')->middleware($scope('webhooks:write'))->name('webhooks.destroy');
    });
