<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Listeners;

use App\Models\Customer;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cookie;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Contracts\Wishlists;
use Juwri\Modules\Storefront\Http\Controllers\RetailPublicController;

/**
 * P3-08 (aisle §12): at a storefront customer's sign-in, the guest wishlist of this browser (the token cookie) is
 * merged into the customer's list at their store and the guest cookie is dropped. Never fails a sign-in.
 */
final class MergeGuestWishlist
{
    public static function onLogin(Login $event): void
    {
        if ($event->guard !== 'customer' || ! $event->user instanceof Customer) {
            return;
        }
        $token = request()->cookie(RetailPublicController::GUEST_COOKIE);
        if (! is_string($token) || $token === '') {
            return;
        }
        $wishlists = app(Wishlists::class);
        $key = $wishlists::guestKey($token);
        $store = $event->user->store_id ? Store::query()->find((int) $event->user->store_id) : null;
        if ($key === null || ! $store instanceof Store) {
            return;
        }
        try {
            $wishlists->merge($store, (int) $event->user->id, $key);
            Cookie::queue(Cookie::forget(RetailPublicController::GUEST_COOKIE));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
