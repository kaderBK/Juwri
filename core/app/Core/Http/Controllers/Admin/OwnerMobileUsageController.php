<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owner dashboard PWA (PWA-SPEC §3.6): which dashboard screens owners open on
 * phones, by surface. This ranked list is the mobile-redesign backlog.
 */
class OwnerMobileUsageController extends Controller
{
    private const DEVICES = ['phone', 'tablet', 'desktop'];

    /** @return array{days:int, device:string, rows:Collection<int, \stdClass>, totals:array<string,int>} */
    private function data(Request $request): array
    {
        $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;
        $device = in_array($request->query('device'), self::DEVICES, true) ? (string) $request->query('device') : 'phone';

        if (! Schema::hasTable('owner_screen_views')) {
            return ['days' => $days, 'device' => $device, 'rows' => collect(), 'totals' => []];
        }

        $base = DB::table('owner_screen_views')
            ->where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->where('device', $device);

        $rows = (clone $base)
            ->select('route_name')
            ->selectRaw('SUM(views) as views')
            ->selectRaw('COUNT(DISTINCT user_id) as users')
            ->selectRaw("SUM(CASE WHEN surface = 'browser' THEN views ELSE 0 END) as browser_views")
            ->selectRaw("SUM(CASE WHEN surface = 'pwa' THEN views ELSE 0 END) as pwa_views")
            ->selectRaw("SUM(CASE WHEN surface = 'app' THEN views ELSE 0 END) as app_views")
            ->groupBy('route_name')
            ->orderByDesc('users')
            ->orderByDesc('views')
            ->limit(200)
            ->get();

        $totals = [
            'views' => (int) (clone $base)->sum('views'),
            'users' => (int) (clone $base)->distinct()->count('user_id'),
            'pwa_users' => (int) (clone $base)->where('surface', 'pwa')->distinct()->count('user_id'),
            'app_users' => (int) (clone $base)->where('surface', 'app')->distinct()->count('user_id'),
        ];

        return compact('days', 'device', 'rows', 'totals');
    }

    public function index(Request $request): View
    {
        return view('admin.owner-mobile-usage.index', $this->data($request) + ['devices' => self::DEVICES]);
    }

    public function export(Request $request): StreamedResponse
    {
        $d = $this->data($request);

        return response()->streamDownload(function () use ($d) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['route', 'views', 'distinct_users', 'browser_views', 'pwa_views', 'app_views'], escape: '\\');
            foreach ($d['rows'] as $r) {
                fputcsv($out, [$r->route_name, $r->views, $r->users, $r->browser_views, $r->pwa_views, $r->app_views], escape: '\\');
            }
            fclose($out);
        }, "owner-{$d['device']}-usage-{$d['days']}d.csv", ['Content-Type' => 'text/csv']);
    }
}
