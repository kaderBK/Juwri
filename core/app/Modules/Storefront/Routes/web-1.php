<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Storefront/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// #85: custom domains per restaurant
// MONETIZATION B7: gated behind feature:custom_domain (dark until V0).
Route::middleware('feature:custom_domain')->group(function () {
    Route::get('stores/{store}/domains', '\Juwri\Modules\Storefront\Http\Controllers\User\DomainController@index')->name('stores.domains.index');
    Route::post('stores/{store}/domains', '\Juwri\Modules\Storefront\Http\Controllers\User\DomainController@store')->name('stores.domains.store');
    Route::post('stores/{store}/domains/subdomain', '\Juwri\Modules\Storefront\Http\Controllers\User\DomainController@claimSubdomain')->name('stores.domains.subdomain');
    Route::post('stores/{store}/domains/{domain}/verify', '\Juwri\Modules\Storefront\Http\Controllers\User\DomainController@verify')->name('stores.domains.verify');
    // P1-20: pick the main address ({domain} = a verified domain id, or `path`).
    Route::post('stores/{store}/domains/{domain}/primary', '\Juwri\Modules\Storefront\Http\Controllers\User\DomainController@primary')->name('stores.domains.primary');
    Route::delete('stores/{store}/domains/{domain}', '\Juwri\Modules\Storefront\Http\Controllers\User\DomainController@destroy')->name('stores.domains.destroy');
}); // end feature:custom_domain group
