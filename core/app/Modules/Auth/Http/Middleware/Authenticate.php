<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * `auth` alias. Unauthenticated browser requests are redirected to the
 * `login` route, JSON requests get a 401. Kept as an app class because tests
 * and route files refer to it.
 *
 * P1-00a: Laravel 13's exception handler no longer falls back to
 * route('login') when the middleware gives no redirect (it answers an empty
 * 401 instead), so the fallback lives here now.
 */
class Authenticate extends Middleware
{
    protected function redirectTo(Request $request)
    {
        return parent::redirectTo($request) ?? (Route::has('login') ? route('login') : null);
    }
}
