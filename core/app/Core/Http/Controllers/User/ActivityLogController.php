<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\User;

use App\Models\ActivityLog;
use App\Services\Backup\DatasetStreamWriter;
use App\Support\SearchTerm;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        // Collect all restaurant IDs owned by this user
        $storeIds = Store::where('user_id', $user->id)->pluck('id');

        $q = ActivityLog::where(function ($query) use ($user, $storeIds) {
            $query->where('actor_user_id', $user->id)
                ->orWhereIn('store_id', $storeIds);
        });

        // Filter by restaurant
        if ($request->filled('store_id')) {
            $rid = (int) $request->get('store_id');
            if ($storeIds->contains($rid)) {
                $q->where('store_id', $rid);
            }
        }

        // Filter by action prefix (P6-315: literal, never a wildcard from the query string)
        if ($request->filled('action')) {
            $prefix = SearchTerm::clean((string) $request->get('action'), 1);
            if ($prefix !== '') {
                $q->whereRaw('action LIKE ? ESCAPE ?', [SearchTerm::like($prefix, true), SearchTerm::ESCAPE]);
            }
        }

        // Filter by who did it
        if ($request->filled('actor')) {
            $q->where('actor_user_id', (int) $request->get('actor'));
        }

        $range = $this->dateRange($request, $storeIds);
        if ($range['from']) {
            $q->where('created_at', '>=', $range['from']);
        }
        if ($range['to']) {
            $q->where('created_at', '<', $range['to']);
        }

        if ($request->get('format') === 'csv') {
            return $this->csv($q->with('actor')->orderByDesc('created_at'), $storeIds);
        }

        $logs = $q->with('store')->orderByDesc('created_at')->paginate(50);

        $stores = Store::where('user_id', $user->id)->orderBy('title')->get();

        $prefixes = self::PREFIXES;
        $actorIds = ActivityLog::where(function ($query) use ($user, $storeIds) {
            $query->where('actor_user_id', $user->id)->orWhereIn('store_id', $storeIds);
        })->whereNotNull('actor_user_id')->distinct()->pluck('actor_user_id');
        $actors = User::whereIn('id', $actorIds)->orderBy('name')->get(['id', 'name', 'email']);

        return view('owner.settings.activity-log', compact('logs', 'stores', 'prefixes', 'actors'));
    }

    /** Action prefixes the app actually logs (log_activity('<prefix>.…')). */
    public const PREFIXES = ['menu', 'items', 'category', 'order', 'restaurant', 'staff', 'table', 'booking', 'coupon', 'giftcard', 'wallet', 'loyalty', 'review', 'csv', 'auth'];

    /**
     * UTC bounds; dates are days in the store's timezone.
     *
     * @param  Collection<int, int>  $storeIds
     * @return array{from: ?Carbon, to: ?Carbon}
     */
    private function dateRange(Request $request, Collection $storeIds): array
    {
        $tz = (string) config('app.timezone', 'UTC');
        $storeId = (int) ($request->get('store_id') ?: ($storeIds->first() ?? 0));
        if ($storeId > 0 && ($store = Store::find($storeId))) {
            try {
                $tz = (string) (store_hours_payload($store)['tz'] ?? $tz);
            } catch (\Throwable) {
            }
        }
        $parse = function (?string $d, bool $end) use ($tz) {
            if (! $d || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return null;
            }
            try {
                $c = Carbon::createFromFormat('Y-m-d', $d, $tz)->startOfDay();
            } catch (\Throwable) {
                return null;
            }

            return ($end ? $c->addDay() : $c)->utc();
        };

        return ['from' => $parse($request->get('from'), false), 'to' => $parse($request->get('to'), true)];
    }

    /**
     * @param  Builder<ActivityLog>  $query
     * @param  Collection<int, int>  $storeIds
     */
    private function csv(Builder $query, Collection $storeIds): StreamedResponse
    {
        $rows = $query->limit(10000)->get();
        $titles = Store::whereIn('id', $storeIds)->pluck('title', 'id');

        return response()->streamDownload(function () use ($rows, $titles) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['time_utc', 'action', 'store', 'actor', 'ip']);
            foreach ($rows as $r) {
                // every cell through the shared formula neutralizer (CsvExportsNeutralizeFormulasTest)
                fputcsv($out, DatasetStreamWriter::csvRow([$r->created_at->utc()->format('Y-m-d H:i:s'), $r->action, $titles[$r->store_id] ?? '', $r->actor?->email, $r->ip]));
            }
            fclose($out);
        }, 'activity-log.csv', ['Content-Type' => 'text/csv']);
    }
}
