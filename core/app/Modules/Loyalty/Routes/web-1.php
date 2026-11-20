<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Loyalty/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// #57: Loyalty program (owner settings)
// MONETIZATION B6: gated behind feature:loyalty (dark until V0). The
// public customer-balance endpoint stays ungated (anonymous diners).
Route::prefix('restaurant/{storeId}/loyalty')->name('loyalty.')->middleware('feature:loyalty')->group(function () {
    Route::get('/', '\Juwri\Modules\Loyalty\Http\Controllers\User\LoyaltyController@settings')->name('settings');
    Route::post('/', '\Juwri\Modules\Loyalty\Http\Controllers\User\LoyaltyController@updateSettings')->name('update');
});
// google-suite Lane C — Google Wallet per-store enable toggle (owner; tenant
// ownership enforced in the controller). Additive; own name.
Route::post('restaurant/{storeId}/google-wallet/toggle', '\Juwri\Modules\Loyalty\Http\Controllers\User\GoogleWalletController@updateStoreToggle')->name('googleWallet.toggle');

Route::controller('\Juwri\Modules\Loyalty\Http\Controllers\User\StoreCouponController')->name('stores.coupons.')->prefix('coupons')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/bulk', 'bulkCreate')->name('bulk.create');
    Route::post('/bulk/confirm', 'bulkConfirm')->middleware('throttle:30,1,stores-coupons-bulk-confirm')->name('bulk.confirm');
    Route::post('/bulk', 'bulkStore')->middleware('throttle:30,1,stores-coupons-bulk-store')->name('bulk.store');
    Route::get('/batches', 'batches')->name('batches.index');
    Route::get('/batches/{batch}', 'batchResult')->whereNumber('batch')->name('batches.show');
    Route::post('/bulk-action', 'bulkAction')->middleware('throttle:30,1,stores-coupons-bulk-action')->name('bulk.action');
    Route::get('/export', 'export')->name('export');
    Route::get('/{coupon}/edit', 'edit')->name('edit');
    Route::get('/{coupon}/redemptions', '\Juwri\Modules\Loyalty\Http\Controllers\User\StoreCouponRedemptionController@index')->name('redemptions'); // C5
    Route::put('/{coupon}', 'update')->name('update');
    Route::delete('/{coupon}', 'destroy')->name('destroy');
});

// T2 gift cards — owner management.
// FIX-2 F2-4: the whole group carried NO feature gate (audit side door) —
// issue/void/adjust move real stored value. Owner-session routes, so the
// `feature:` subject (dashboard owner) is the correct one here.
Route::controller('\Juwri\Modules\Loyalty\Http\Controllers\User\GiftCardAdminController')->name('stores.giftcards.')->prefix('gift-cards')->middleware('feature:gift_cards')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/issue', 'issue')->name('issue');
    Route::post('/{id}/void', 'void')->name('void');
    Route::post('/{id}/adjust', 'adjust')->name('adjust');
    // P6-214: owner-initiated resend after a delivery failure.
    Route::post('/{id}/resend', 'resend')->name('resend');
});
