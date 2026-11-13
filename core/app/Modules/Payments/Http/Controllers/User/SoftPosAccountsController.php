<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Services\Terminal\SoftPos\SoftPos;

/**
 * P5-29: owner "Card terminals" page — set up the store's own SoftPOS acquirer
 * account (Geidea / EdfaPay). Read-mostly: the page renders the masked accounts
 * from the service and posts to the existing POS JSON endpoint; it never handles
 * raw credentials itself.
 */
class SoftPosAccountsController extends Controller
{
    public function show(Request $request, SoftPos $softPos): View
    {
        $storeIds = Store::where('user_id', auth()->id())->pluck('id')->toArray();
        $storeId = (int) $request->query('store_id', dashboard_current_store()->id ?? $storeIds[0] ?? 0);

        if ($storeId && ! in_array($storeId, $storeIds, true)) {
            abort(403);
        }

        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);
        $store = Store::whereIn('id', $storeIds)->find($storeId);

        $providers = $softPos->enabledProviders();
        $accounts = $store ? collect($softPos->accounts($store))->keyBy('provider')->all() : [];

        $callbackUrls = [];
        if ($store) {
            foreach ($providers as $provider) {
                $callbackUrls[$provider] = route('webhook.softpos', ['provider' => $provider, 'store' => $store->id]);
            }
        }

        return view('owner.payments.card-terminals', compact('store', 'providers', 'accounts', 'callbackUrls', 'stores'));
    }
}
