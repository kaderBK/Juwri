<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Support;

use Illuminate\Support\Facades\Route;

/**
 * P1-13 13g: where a buyer of a platform purchase goes back to (the subscription / add-ons / wallet pages, the admin
 * transactions list). Those pages are Billing's (ee); without it (Community) the dashboard is the place to land — a
 * store's own customer payments go back to the storefront and never reach here.
 */
final class AccountPages
{
    public static function url(string $route = 'subscription', string $fallback = 'dashboard'): string
    {
        return route(Route::has($route) ? $route : $fallback);
    }
}
