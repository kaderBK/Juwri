<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\StoreFollow;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Customers\Http\Middleware\CustomerTokenAuth;

/**
 * theme-pack-blade · F2 Follow / subscribe-to-brand.
 *
 * Authenticated by {@see CustomerTokenAuth}: only a logged-in
 * customer can follow. The customer is resolved from the token (app('api.customer')),
 * never from the request body, so a customer can only ever follow on their own behalf.
 * Follow/unfollow are both idempotent — the DB composite unique guarantees at most
 * one edge per (customer, restaurant).
 */
class CustomerFollowController extends Controller
{
    private function customer(): Customer
    {
        return app('api.customer');
    }

    /**
     * Resolve the {store} route binding (id or slug) to a Post, scoped so a
     * customer can only follow a restaurant within their own tenant.
     */
    private function resolveRestaurant($store, Customer $customer): ?Store
    {
        return Store::query()
            ->idOrSlug($store)
            ->get()
            ->first(fn (Store $p) => (int) $p->id === (int) $customer->store_id);
    }

    /** POST /r/{store}/follow */
    public function store(Request $request, $store)
    {
        $customer = $this->customer();
        $store = $this->resolveRestaurant($store, $customer);

        if (! $store) {
            return response()->json(['error' => 'Restaurant not found.'], 404);
        }

        // Idempotent: firstOrCreate honours the composite unique, so a repeated
        // follow returns the existing edge rather than erroring or duplicating.
        $follow = StoreFollow::firstOrCreate([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
        ]);

        // B6: the "Following" sheet toggles this customer's notification opt-out
        // through the same endpoint (body {notify: 0|1}) — a no-op unless present,
        // so a plain follow keeps its default. Only ever the caller's own edge.
        if ($request->has('notify')) {
            $follow->notify = $request->boolean('notify');
            $follow->save();
        }

        return response()->json([
            'following' => true,
            'follower_count' => (int) $store->follows()->count(),
            // A freshly firstOrCreate'd row hasn't loaded the DB default yet — coalesce
            // to the column default (true); an existing opt-out keeps its stored false.
            'notify' => (bool) ($follow->notify ?? true),
        ]);
    }

    /** DELETE /r/{store}/follow */
    public function destroy($store)
    {
        $customer = $this->customer();
        $store = $this->resolveRestaurant($store, $customer);

        if (! $store) {
            return response()->json(['error' => 'Restaurant not found.'], 404);
        }

        // Idempotent: unfollowing when not following is a no-op that still reports
        // the truthful state.
        StoreFollow::where('customer_id', $customer->id)
            ->where('store_id', $store->id)
            ->delete();

        return response()->json([
            'following' => false,
            'follower_count' => (int) $store->follows()->count(),
        ]);
    }
}
