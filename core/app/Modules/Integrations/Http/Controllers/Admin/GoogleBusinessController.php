<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * google-partner Lane A / A7 — superadmin oversight of GBP-connected stores.
 *
 * Read-only list (store, location, last menu sync, sync error, link state) plus a
 * per-store kill switch (deactivate the credential → Credential::for() stops returning
 * it, so menu sync no-ops and the ordering-link service refuses). Admin guard via the
 * route group (['admin','demo','csp.dashboard']).
 *
 * Reseller bulk-connect (Credential::forReseller) is PARKED — see the ledger A7
 * [BLOCKED: owner-decision]; recommendation is per-owner connect only for now.
 */
class GoogleBusinessController extends Controller
{
    public function index(Request $request): View
    {
        $rows = Credential::where('feature', 'gbp')->orderByDesc('id')->get()
            ->map(function (Credential $c) {
                $s = $c->secrets ?? [];
                $linked = ! empty($s['location_id']);
                $oauth = ! empty($s['access_token']);

                return [
                    'id'            => $c->id,
                    'store_id' => $c->store_id,
                    'store'         => optional(Store::find($c->store_id))->title ?? ('#' . $c->store_id),
                    'location_id'   => $s['location_id'] ?? '',
                    'state'         => $linked ? 'linked' : ($oauth ? 'connected-no-location' : 'incomplete'),
                    'menu_sync'     => ! empty($s['gbp_menu_sync_enabled']),
                    'last_synced_at' => $s['last_synced_at'] ?? null,
                    'sync_error'    => $s['sync_error'] ?? null,
                    'active'        => (bool) $c->active,
                ];
            });

        return view('admin.google-business.index', [
            'rows'  => $rows,
            'total' => $rows->count(),
        ]);
    }

    /** Per-store kill / re-enable: flip the credential's active flag. */
    public function toggle(Request $request, int $id): RedirectResponse
    {
        $cred = Credential::where('feature', 'gbp')->findOrFail($id);
        $cred->active = ! $cred->active;
        $cred->save();

        return back()->with('success', $cred->active
            ? ___('Google Business connection re-enabled.')
            : ___('Google Business connection disabled.'));
    }
}
