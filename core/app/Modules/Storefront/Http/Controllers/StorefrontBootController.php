<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontAvailability;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontBoot;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelJson;

/**
 * P3-03: GET {store}/storefront/boot.json — the same boot data the page partial prints (the view model's endpoints,
 * order types, kiosk and offline-queue blocks, core strings, and the per-store service worker URL with the EFFECTIVE
 * queue switch). For a client that has no server-rendered page: the headless storefront, a PWA shell refreshing its
 * switches, and the browser test of the core JS. Public data only; never cached (`no-store`), so a switched-off queue
 * is seen on the next call.
 */
class StorefrontBootController extends Controller
{
    public function __invoke(Request $request, string $store, StorefrontBoot $boot): JsonResponse
    {
        $store = Store::query()->idOrSlug($store)->first();
        if ($store === null || ! StorefrontAvailability::servable($store)) {
            return ApiError::status(404, 'Store not found.');
        }

        $table = $request->query('table');
        $data = $boot->forStore($store, is_string($table) && $table !== '' ? mb_substr($table, 0, 120) : null);

        return response()->json(json_decode(ViewModelJson::encode($data)), 200, ['Cache-Control' => 'no-store, private']);
    }
}
