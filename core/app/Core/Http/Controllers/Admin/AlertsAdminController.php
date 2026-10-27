<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\PlatformAlert;
use Illuminate\Http\Request;

/**
 * O4 — superadmin Platform Alerts console: list persisted platform alerts
 * (render/scheduler/backup/queue/abuse) and mark them resolved.
 */
class AlertsAdminController extends Controller
{
    public function index(Request $request)
    {
        $show = $request->get('show') === 'resolved' ? 'resolved' : 'open';
        $kind = $request->get('kind');

        $query = PlatformAlert::orderByDesc('created_at');
        $show === 'resolved' ? $query->whereNotNull('resolved_at') : $query->whereNull('resolved_at');
        // WI-M6: filter to a single kind (e.g. the new `payment` alerts) so an operator can answer
        // "is anything wrong with payments right now?" in one click.
        if ($kind !== null && $kind !== '') {
            $query->where('kind', $kind);
        }

        return view('admin.alerts.index', [
            'alerts'     => $query->paginate(50),
            'show'       => $show,
            'kind'       => $kind,
            'openCount'  => PlatformAlert::whereNull('resolved_at')->count(),
            'weekCount'  => PlatformAlert::where('created_at', '>=', now()->subDays(7))->count(),
            // WI-M6: payment failures are security/money signal, not noise.
            'paymentsOpen' => PlatformAlert::whereNull('resolved_at')->where('kind', 'payment')->count(),
        ]);
    }

    public function resolve(Request $request, int $id)
    {
        PlatformAlert::whereKey($id)->whereNull('resolved_at')->update(['resolved_at' => now()]);

        return back()->with('success', ___('Alert resolved.'));
    }
}
