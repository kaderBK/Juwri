<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\AggregatorConnection;

/**
 * D5 (integrations pack): the superadmin integrations monitor. Cross-tenant health
 * for the whole platform in one page —
 *   - webhook failure leaderboard (which tenants' endpoints are failing, last 7d),
 *   - every Deliverect location link and its sync state,
 *   - the two kill switches with their current state + a jump to flip them.
 *
 * Admin-gated (the `admin` middleware group); NO feature gate — a superadmin always
 * sees the console, even while the feature ships dark. The leaderboard is cached
 * ~60s so opening the page never full-scans `webhook_deliveries` per request.
 */
class IntegrationsMonitorController extends Controller
{
    public function index(): View
    {
        $leaderboard = Cache::remember('admin.integrations.webhook_fails', 60, function () {
            $p = DB::getTablePrefix();

            $rows = DB::table('webhook_deliveries')
                ->join('webhook_endpoints', 'webhook_endpoints.id', '=', 'webhook_deliveries.endpoint_id')
                ->where('webhook_deliveries.success', false)
                ->where('webhook_deliveries.created_at', '>=', now()->subDays(7))
                ->selectRaw("{$p}webhook_endpoints.user_id as user_id, COUNT(*) as failures, MAX({$p}webhook_deliveries.created_at) as last_failure")
                ->groupBy('webhook_endpoints.user_id')
                ->orderByDesc('failures')
                ->limit(20)
                ->get();

            // Resolve tenant labels inside the cache closure so the view stays dumb.
            $names = User::whereIn('id', $rows->pluck('user_id'))->pluck('name', 'id');

            return $rows->map(fn ($r) => [
                'user_id' => (int) $r->user_id,
                'name' => $names[$r->user_id] ?? ('#'.$r->user_id),
                'failures' => (int) $r->failures,
                'last_failure' => $r->last_failure,
            ])->all();
        });

        $connections = AggregatorConnection::where('provider', 'deliverect')
            ->with('store:id,title,user_id')
            ->orderByDesc('last_synced_at')
            ->limit(100)
            ->get();

        $killSwitches = [
            'integrations' => (bool) config('settings.admin_allow_integrations', false),
            'aggregator_deliverect' => (bool) config('settings.admin_allow_aggregator_deliverect', false),
        ];

        return view('admin.integrations.index', compact('leaderboard', 'connections', 'killSwitches'));
    }
}
