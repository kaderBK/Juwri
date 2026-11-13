<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Juwri\Modules\Storefront\Services\StorePath;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;

/**
 * P5-20 (ROADMAP 24 G3): the Ordering Redirect landing route. Google sends the diner to `{slug}/order/{type}`
 * (or `order/{type}` on the store's own host — ResolveStoreHost rewrites it onto this route). This answers 302 to
 * the store home in the same locale: `?order_type=<kit key>` (which the storefront preselects) plus any incoming
 * `utm_*`, or no order_type for a type the store does not offer. An unknown store is the normal 404. No login,
 * interstitial or cookie wall.
 */
class GoogleOrderingLinkController extends Controller
{
    public function link(Request $request, string $slug, string $type, StoreAddressResolver $resolver): RedirectResponse
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();

        $params = [];
        $core = OrderTypeKeys::toCoreFor($type, $this->offered($store));
        if ($core !== null) {
            $kit = OrderTypeKeys::toKit($core);
            if ($kit !== null) {
                $params['order_type'] = $kit;
            }
        }
        $branch = $request->query('branch');
        if (is_string($branch) && \Illuminate\Support\Str::isUuid($branch) && $this->activeBranchOfStore($store, $branch)) {
            $params['branch'] = $branch;
        }
        foreach ($request->query() as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'utm_') && is_scalar($value) && count($params) < 8) {
                $params[substr($key, 0, 40)] = substr((string) $value, 0, 200); // bounded: the Location header stays small
            }
        }

        $locale = StorePath::split($request->getPathInfo())['locale'];
        $home = $resolver->localized((int) $store->id, (string) $store->slug, $locale, '');
        if ($params !== []) {
            $home .= '?'.http_build_query($params);
        }

        return redirect()->to($home, 302);
    }

    /**
     * P5-20 part F: the `branch` query param is kept only when it is the public_id of an ACTIVE
     * branch of THIS store. Another store's branch, an inactive branch or garbage is dropped —
     * never an error.
     */
    private function activeBranchOfStore(Store $store, string $publicId): bool
    {
        if (! \Illuminate\Support\Str::isUuid($publicId)) {
            return false; // garbage never reaches a uuid column (PostgreSQL rejects it)
        }

        return Branch::query()
            ->where('store_id', $store->id)
            ->where('public_id', $publicId)
            ->where('active', 1)
            ->exists();
    }

    /** @return list<string> */
    private function offered(Store $store): array
    {
        try {
            return array_map('strval', (array) app(StorefrontOrdering::class)->orderTypes($store));
        } catch (\Throwable) {
            return [];
        }
    }
}
