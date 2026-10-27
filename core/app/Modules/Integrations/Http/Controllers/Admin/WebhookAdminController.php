<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;

/**
 * Superadmin observability for the outbound webhook system (#99):
 * every registered endpoint across all tenants plus the recent
 * delivery log, with health stats at the top.
 */
class WebhookAdminController extends Controller
{
    public function index(Request $request)
    {
        $status = in_array($request->get('status'), ['active', 'disabled'], true) ? $request->get('status') : 'all';
        $result = in_array($request->get('result'), ['success', 'failed'], true) ? $request->get('result') : 'all';

        /* Stats row */
        $totalEndpoints  = WebhookEndpoint::count();
        $activeEndpoints = WebhookEndpoint::where('active', true)->count();

        $since        = now()->subDays(7);
        $deliveries7d = WebhookDelivery::where('created_at', '>=', $since)->count();
        $failed7d     = WebhookDelivery::where('created_at', '>=', $since)->where('success', false)->count();
        $failureRate7d = $deliveries7d > 0 ? round($failed7d / $deliveries7d * 100, 1) : 0.0;

        /* Endpoints card */
        $endpointQuery = WebhookEndpoint::orderByDesc('created_at');
        if ($status === 'active') {
            $endpointQuery->where('active', true);
        } elseif ($status === 'disabled') {
            $endpointQuery->where('active', false);
        }
        $endpoints = $endpointQuery->paginate(20);

        // Owner lookup without N+1 (single whereIn instead of per-row queries).
        $owners = User::whereIn('id', $endpoints->pluck('user_id')->filter()->unique())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        /* Recent deliveries card (latest 25, filtered by result) */
        $deliveryQuery = WebhookDelivery::orderByDesc('created_at')->orderByDesc('id');
        if ($result === 'success') {
            $deliveryQuery->where('success', true);
        } elseif ($result === 'failed') {
            $deliveryQuery->where('success', false);
        }
        $deliveries = $deliveryQuery->take(25)->get();

        // WebhookDelivery has no endpoint() relation; map URLs by id in one query.
        $endpointUrls = WebhookEndpoint::whereIn('id', $deliveries->pluck('endpoint_id')->unique())
            ->pluck('url', 'id');

        return view('admin.webhooks.index', compact(
            'totalEndpoints',
            'activeEndpoints',
            'deliveries7d',
            'failureRate7d',
            'endpoints',
            'owners',
            'deliveries',
            'endpointUrls',
            'status',
            'result'
        ));
    }
}
