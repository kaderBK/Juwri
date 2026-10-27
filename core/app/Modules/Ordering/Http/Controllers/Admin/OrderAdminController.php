<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\Admin;

use App\Models\Order;
use App\Support\SearchTerm;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Juwri\Core\Http\Controllers\Controller;

class OrderAdminController extends Controller
{
    public function index(Request $request)
    {
        // P6-251: "today" is the platform admin's calendar day (settings timezone), as a half-open range on the stored column.
        $platformTz = (string) (config('settings.timezone') ?: config('app.timezone') ?: 'UTC');
        $dayStart = Carbon::now($platformTz)->startOfDay();
        $appTz = (string) (config('app.timezone') ?: 'UTC');
        $stats = [
            'today' => Order::where('created_at', '>=', $dayStart->copy()->setTimezone($appTz))
                ->where('created_at', '<', $dayStart->copy()->addDay()->startOfDay()->setTimezone($appTz))->count(),
            'week' => Order::where('created_at', '>=', Carbon::now()->subDays(7))->count(),
            'month' => Order::where('created_at', '>=', Carbon::now()->subDays(30))->count(),
            'inflight' => Order::whereIn('delivery_status', ['assigned', 'picked_up', 'out_for_delivery'])->count(),
            // H10: rows the snapshot-based revenue sums under-report until backfilled.
            'unsnapshotted' => Order::whereNull('grand_total_minor')->count(),
        ];

        $query = Order::with(['items', 'store.user'])->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        if ($request->filled('source')) {
            $query->where('source', $request->string('source'));
        }
        if (in_array((int) $request->query('days'), [1, 7, 30], true)) {
            $query->where('created_at', '>=', Carbon::now()->subDays((int) $request->query('days')));
        }
        if ($request->filled('store')) {
            $term = trim((string) $request->query('store'));
            $query->whereHas('store', fn ($q) => SearchTerm::apply($q, ['title'], $term));
        }

        $orders = $query->paginate(50)->withQueryString();

        $statuses = Order::query()->select('status')->distinct()->orderBy('status')->pluck('status')->filter()->values();

        return view('admin.orders.index', [
            'stats' => $stats,
            'orders' => $orders,
            'statuses' => $statuses,
            'filters' => $request->only(['status', 'type', 'source', 'days', 'restaurant']),
        ]);
    }

    /** H10: fill the order total snapshot columns for rows that predate the R1c migration. */
    public function backfillTotals(Request $request): RedirectResponse
    {
        // Queued on `heavy` (the queue the platform already uses for long jobs). On a
        // `sync` connection this runs inline; that is acceptable on a small install and
        // is why the button carries a confirm.
        Artisan::queue('orders:snapshot-totals', ['--chunk' => 500])->onQueue('heavy');

        return back()->with('success', ___('Order totals backfill queued.'));
    }
}
