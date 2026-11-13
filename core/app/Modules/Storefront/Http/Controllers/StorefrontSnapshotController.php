<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontAvailability;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelJson;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelRequest;

/**
 * P3-03: GET {store}/storefront/snapshot.json — the versioned menu snapshot the per-store service worker keeps for
 * offline browsing (THEME-CONTRACT §5.5): the catalogue part of the view model (store, catalogue, money, strings),
 * anonymous (no customer, no cart, no page), in the request's language. `version` is a hash of the content, also
 * the ETag, so an unchanged menu answers 304 and the worker replaces the stored copy only when it changed.
 * Public data only (the same a visitor of the store page sees); `no-cache` so it revalidates every time.
 */
class StorefrontSnapshotController extends Controller
{
    public function __invoke(Request $request, string $store, ViewModelBuilder $builder): JsonResponse|Response
    {
        $store = Store::query()->idOrSlug($store)->first();
        if ($store === null || ! StorefrontAvailability::servable($store)) {
            return ApiError::status(404, 'Store not found.');
        }

        $vm = $builder->build(new ViewModelRequest(store: $store, locale: app()->getLocale(), page: 'home'));
        $body = [
            'store' => $vm['store'],
            'branch' => $vm['branch'],
            'catalog' => $vm['catalog'],
            'money' => $vm['money'],
            'i18n' => $vm['i18n'],
        ];
        $json = ViewModelJson::encode($body);
        $version = substr(hash('sha256', $json), 0, 16);
        $etag = '"'.$version.'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'no-cache, public'];

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response('{"version":"'.$version.'","snapshot":'.$json.'}', 200, $headers + ['Content-Type' => 'application/json']);
    }
}
