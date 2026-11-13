<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiException;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontAvailability;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3-06b (kit/api README §2.5): every headless storefront endpoint runs the servability gate the web storefront and
 * the public menu run (StorefrontAvailability: suspended owner, inactive plan, unpublished pharmacy) — a store that
 * is not served answers 404 `not_found`, never its data. Runs after StorefrontTokenAuth (the store is bound).
 */
class StorefrontServable
{
    public function handle(Request $request, Closure $next): Response
    {
        $store = app()->bound('api.storefront_post') ? app('api.storefront_post') : null;
        if ($store === null || (StorefrontAvailability::apiGateEnabled() && ! StorefrontAvailability::servable($store))) {
            return StorefrontApiException::notFound()->render();
        }

        return $next($request);
    }
}
