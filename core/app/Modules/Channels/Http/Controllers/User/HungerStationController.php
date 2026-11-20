<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers\User;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Channels\Models\HsEventLog;

/**
 * P5 — owner HungerStation settings: connect BYOK credentials, map branch->vendor,
 * view the event log. Everything scoped to a restaurant the owner owns.
 */
class HungerStationController extends Controller
{
    private function own(int $storeId): Store
    {
        return Store::where('id', $storeId)->where('user_id', auth()->id())->firstOrFail();
    }

    /** Settings page: connect BYOK, map branches, view the event log. */
    public function page(Request $request)
    {
        $stores = Store::where('user_id', auth()->id())->get(['id', 'title']);
        $active = (int) ($request->query('store') ?: (dashboard_current_store()->id ?? $stores->first()->id ?? 0)) ?: null;
        $branches = collect();
        $connected = false;
        $events = collect();
        if ($active) {
            $this->own($active);
            $branches = Branch::where('store_id', $active)->get(['id', 'name', 'hs_vendor_id', 'hs_chain_id']);
            $connected = Credential::where('store_id', $active)->where('feature', 'hungerstation')->where('active', true)->exists();
            $events = HsEventLog::where('store_id', $active)->orderByDesc('id')->limit(50)->get();
        }

        return view('owner.integrations.hungerstation', compact('stores', 'active', 'branches', 'connected', 'events'));
    }

    /** Store/refresh BYOK credentials (secrets encrypted by the Credential cast). */
    public function connect(Request $request, int $storeId)
    {
        $this->own($storeId);
        $d = $request->validate([
            'client_id' => 'required|string|max:190', 'client_secret' => 'required|string|max:190',
            'chain_id' => 'nullable|string|max:64', 'webhook_secret' => 'nullable|string|max:190',
        ]);
        Credential::updateOrCreate(
            ['store_id' => $storeId, 'feature' => 'hungerstation'],
            ['provider' => 'hungerstation', 'secrets' => $d, 'active' => true]
        );

        return response()->json(['connected' => true]);
    }

    /** Map an owned branch to its HS vendor id. */
    public function mapBranch(Request $request, int $storeId)
    {
        $this->own($storeId);
        $d = $request->validate(['branch_id' => 'required|integer', 'hs_vendor_id' => 'required|string|max:64', 'hs_chain_id' => 'nullable|string|max:64']);
        $branch = Branch::where('id', $d['branch_id'])->where('store_id', $storeId)->firstOrFail();
        // AUDIT-tenancy U1: inbound webhooks resolve the branch by hs_vendor_id, so a vendor id held by another branch
        // (any tenant) must not be claimed — it would reroute that tenant's orders here.
        $request->validate(['hs_vendor_id' => [Rule::unique('branches', 'hs_vendor_id')->ignore($branch->id)]]);
        $branch->update(['hs_vendor_id' => $d['hs_vendor_id'], 'hs_chain_id' => $d['hs_chain_id'] ?? null]);

        return response()->json(['ok' => true]);
    }

    public function events(int $storeId)
    {
        $this->own($storeId);

        return response()->json(HsEventLog::where('store_id', $storeId)->orderByDesc('id')->limit(100)->get());
    }
}
