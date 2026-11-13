<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin;

use App\Models\ActivityLog;
use App\Support\SearchTerm;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;

class ActivityLogAdminController extends Controller
{
    public function index(Request $request)
    {
        $days = (int) $request->get('days', 7);
        if (! in_array($days, [1, 7, 30], true)) {
            $days = 7;
        }

        $query = ActivityLog::query()->where('created_at', '>=', now()->subDays($days));

        if ($request->filled('action')) {
            $query->where('action', $request->get('action'));
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->get('store_id'));
        }

        if ($request->filled('actor')) {
            $term = trim((string) $request->get('actor'));
            $actorIds = User::where(function ($q) use ($term) {
                SearchTerm::apply($q, ['name', 'email'], $term);
            })->pluck('id');
            $query->whereIn('actor_user_id', $actorIds);
        }

        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(50);

        // Map actors + restaurants for the current page in one query each (no N+1).
        $actors = User::whereIn('id', $logs->pluck('actor_user_id')->filter()->unique()->values())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');
        $stores = Store::whereIn('id', $logs->pluck('store_id')->filter()->unique()->values())
            ->get(['id', 'title'])
            ->keyBy('id');

        $stats = [
            'last24h' => ActivityLog::where('created_at', '>=', now()->subDay())->count(),
            'last7d' => ActivityLog::where('created_at', '>=', now()->subDays(7))->count(),
            'actors7d' => ActivityLog::where('created_at', '>=', now()->subDays(7))
                ->whereNotNull('actor_user_id')->distinct()->count('actor_user_id'),
            'restaurants7d' => ActivityLog::where('created_at', '>=', now()->subDays(7))
                ->whereNotNull('store_id')->distinct()->count('store_id'),
            // WI-H2: blocked impersonation attempts are security signal, not noise.
            'impersonationBlocked7d' => ActivityLog::where('action', 'impersonation.blocked')
                ->where('created_at', '>=', now()->subDays(7))->count(),
        ];

        $actions = ActivityLog::query()->distinct()->orderBy('action')->pluck('action');

        return view('admin.activity.index', compact('logs', 'actors', 'stores', 'stats', 'actions', 'days'));
    }
}
