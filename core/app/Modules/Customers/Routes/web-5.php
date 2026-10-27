<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Customers/web-5); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

Route::post('{store}/customer/profile', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@updateProfile')->middleware('throttle:10,1,customer-profile')->name('customer.profile.update');
// P6-304: a changed phone / e-mail is parked until proven (SMS code / signed link).
Route::post('{store}/customer/profile/phone/verify', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@verifyProfilePhone')->middleware(['throttle:10,1,otp-verify', 'ratelimit:public-write'])->name('customer.profile.phone.verify');
Route::get('{store}/customer/profile/email/verify/{customer}', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@verifyProfileEmail')->middleware(['signed', 'throttle:10,1,customers-customer-claim-verify'])->name('customer.profile.email.verify');
/* google-suite Lane A (additive) — disconnect a linked social provider from the
   signed-in customer (customer guard enforced in the controller). */
Route::post('{store}/customer/social/unlink', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@unlinkSocial')->name('customer.social.unlink');
Route::post('{store}/customer/addresses', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@storeAddress')->name('customer.addresses.store');
Route::put('{store}/customer/addresses/{address}', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@updateAddress')->name('customer.addresses.update');
Route::delete('{store}/customer/addresses/{address}', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@destroyAddress')->name('customer.addresses.destroy');
// P6-91: shopper self-service data export + account deletion (re-auth required; exports 3 a day per customer).
Route::post('{store}/customer/privacy/code', '\Juwri\Modules\Customers\Http\Controllers\User\ShopperPrivacyController@code')->middleware(['throttle:5,1,otp-send', 'storecap:sms'])->name('customer.privacy.code');
Route::post('{store}/customer/privacy/export', '\Juwri\Modules\Customers\Http\Controllers\User\ShopperPrivacyController@export')->middleware('throttle:10,1,customer-privacy')->name('customer.privacy.export');
Route::post('{store}/customer/privacy/delete', '\Juwri\Modules\Customers\Http\Controllers\User\ShopperPrivacyController@destroy')->middleware('throttle:10,1,customer-privacy')->name('customer.privacy.delete');
Route::post('{store}/customer/orders/{order}/reorder', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerAuthController@reorder')->name('customer.orders.reorder');
