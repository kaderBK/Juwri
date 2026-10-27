<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/apiv1.php (slot Team/apiv1-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// Staff app (POS / KDS / waiter), authenticated by a per-staff token.
Route::prefix('api/v1/staff')
    ->namespace('Api\V1')
    ->middleware(['api', 'Juwri\Modules\Team\Http\Middleware\StaffTokenAuth', 'throttle:180,1,team-apiv1-1'])
    ->name('api.v1.staff.')
    ->group(function () {
        Route::get('me', 'StaffAppController@me')->name('me');
        Route::get('orders', 'StaffAppController@orders')->name('orders');
        Route::put('orders/{orderId}/status', 'StaffAppController@updateOrderStatus')->name('orders.status');
        Route::get('kds', 'StaffAppController@kdsTickets')->name('kds');
        Route::put('kds/items/{itemId}/bump', 'StaffAppController@kdsBump')->name('kds.bump');
        Route::put('kds/items/{itemId}/recall', 'StaffAppController@kdsRecall')->name('kds.recall');
        // Menu editing (permission: menu_edit)
        Route::post('menu/items', 'StaffAppController@storeMenuItem')->name('menu.items.store');
        Route::put('menu/items/{itemId}', 'StaffAppController@updateMenuItem')->name('menu.items.update');
        Route::delete('menu/items/{itemId}', 'StaffAppController@deleteMenuItem')->name('menu.items.delete');
        Route::patch('menu/items/{itemId}/sold-out', 'StaffAppController@toggleSoldOut')->name('menu.items.soldout');
        Route::post('menu/categories', 'StaffAppController@storeMenuCategory')->name('menu.categories.store');
        // Analytics (permission: analytics)
        Route::get('analytics', 'StaffAppController@analytics')->name('analytics');
        // HPMS E11: native push device registration for Bearer (token) clients.
        Route::post('push/register-device', 'StaffAppController@registerDevice')->name('push.register');
        Route::post('push/unregister-device', 'StaffAppController@unregisterDevice')->name('push.unregister');
        // HPMS E12(b): optional TOTP enrolment for email logins.
        Route::post('2fa/setup', 'StaffAppController@twoFactorSetup')->name('2fa.setup');
        Route::post('2fa/confirm', 'StaffAppController@twoFactorConfirm')->name('2fa.confirm');
        Route::post('logout', 'StaffAppController@logout')->name('logout');
    });
