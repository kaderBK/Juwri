<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Loyalty/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Middleware\AddCspHeaders;

// #57: Loyalty — customer-facing balance and redeem (auth:customer)
Route::middleware('auth:customer')->group(function () {
    Route::get('restaurant/{storeId}/loyalty/balance', '\Juwri\Modules\Loyalty\Http\Controllers\User\LoyaltyController@customerBalance')->name('publicView.loyalty.balance');
    Route::post('restaurant/{storeId}/loyalty/redeem', '\Juwri\Modules\Loyalty\Http\Controllers\User\LoyaltyController@redeem')->name('publicView.loyalty.redeem')->middleware('throttle:10,1,loyalty-restaurant-loyalty-redeem');
    // --- google-suite Lane C (Google Wallet passes) — additive; mints the save-link
    //     JWT on demand for the authed customer (loyalty) / an owned gift card. ---
    Route::get('restaurant/{storeId}/google-wallet/loyalty-jwt', '\Juwri\Modules\Loyalty\Http\Controllers\User\GoogleWalletController@loyaltyJwt')->name('publicView.googleWallet.loyaltyJwt')->middleware('throttle:30,1,loyalty-restaurant-google-wallet-loyalty-jwt');
    Route::get('restaurant/{storeId}/google-wallet/gift-card/{giftCardId}/jwt', '\Juwri\Modules\Loyalty\Http\Controllers\User\GoogleWalletController@giftCardJwt')->name('publicView.googleWallet.giftCardJwt')->middleware('throttle:30,1,loyalty-restaurant-google-wallet-gift-card-jwt');
    // Notification-center inbox (logged-in diner) — scoped to the authed customer.
    Route::get('restaurant/{storeId}/notifications', '\Juwri\Modules\Ordering\Http\Controllers\User\CustomerNotificationController@index')->middleware('ratelimit:dashboard_poll')->name('publicView.notifications.index');
    // The bell's badge count — a lightweight poll (the count only, no rows).
    Route::get('restaurant/{storeId}/notifications/unread', '\Juwri\Modules\Ordering\Http\Controllers\User\CustomerNotificationController@unread')->middleware('ratelimit:dashboard_poll')->name('publicView.notifications.unread');
    Route::post('restaurant/{storeId}/notifications/read', '\Juwri\Modules\Ordering\Http\Controllers\User\CustomerNotificationController@markRead')->name('publicView.notifications.read')->middleware('throttle:30,1,loyalty-restaurant-notifications-read');
    // Order history (logged-in diner) — scoped to the authed customer + this store.
    Route::get('restaurant/{storeId}/my-orders', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerOrderController@index')->middleware('ratelimit:dashboard_poll')->name('publicView.myOrders.index');
    // Customer-initiated refund request (owner reviews -> refunds to store credit).
    Route::post('restaurant/{storeId}/orders/{order}/refund-request', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerOrderController@requestRefund')->middleware('throttle:10,1,loyalty-restaurant-orders-refund-request')->name('publicView.myOrders.refundRequest');
    // Customer wallet (store credit) — balance + self-service gateway top-up.
    Route::get('restaurant/{storeId}/wallet/balance', '\Juwri\Modules\Loyalty\Http\Controllers\User\CustomerWalletController@balance')->middleware('ratelimit:dashboard_poll')->name('publicView.wallet.balance');
    Route::get('restaurant/{storeId}/wallet/topup', '\Juwri\Modules\Loyalty\Http\Controllers\User\CustomerWalletController@show')->name('publicView.wallet.topup')->middleware(AddCspHeaders::class); // P6-122b (AddPaymentPageCsp replaces it with the top-up page's payment policy)
    Route::post('restaurant/{storeId}/wallet/topup', '\Juwri\Modules\Loyalty\Http\Controllers\User\CustomerWalletController@topup')->middleware('throttle:restaurant-order')->name('publicView.wallet.topup.start');
});
