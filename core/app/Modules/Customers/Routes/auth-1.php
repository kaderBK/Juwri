<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/auth.php (slot Customers/auth-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* FEATURES-5 Track A — Customer social sign-in (Google/Facebook/Apple).
   Registered BEFORE the owner `auth/{provider}` routes so the more specific
   `auth/social/...` paths win, and OUTSIDE the `{store}` catch-all. The
   OAuth callback is ONE fixed global URL per provider; the restaurant is carried
   in an encrypted state param. Apple posts back (form_post) — hence the POST. */
Route::get('auth/social/customer/{provider}/callback', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerSocialAuthController@callback')->middleware('throttle:30,1,customers-auth-social-customer-callback')->name('customer.social.callback');
Route::post('auth/social/customer/{provider}/callback', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerSocialAuthController@callback')->middleware('throttle:30,1,customers-auth-social-customer-callback')->name('customer.social.callback.post');
Route::get('auth/social/customer/{store}/{provider}', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerSocialAuthController@redirect')
    ->middleware('throttle:30,1,customers-auth-social-customer')->name('customer.social.redirect');

/* google-suite Lane A (A6, additive) — reseller/custom-domain bounce. The OAuth
   callback above runs on the fixed platform host; when the store's canonical origin
   is a custom domain, the callback bounces the browser HERE (on that domain) with a
   single-use, server-side-burned login token to establish the session on the origin. */
Route::get('auth/social/customer/bounce', '\Juwri\Modules\Customers\Http\Controllers\User\CustomerSocialAuthController@bounce')
    ->middleware('throttle:30,1,customers-auth-social-customer-bounce')->name('customer.social.bounce');
