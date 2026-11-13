<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\User;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Backup\DatasetStreamWriter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\Sql;

class CustomerController extends Controller
{
    public function __construct()
    {
        $this->activeTheme = active_theme();
    }

    public function index(Request $request)
    {
        $customers = $this->customerQuery($request)->paginate(20);

        return view('owner.customers.index', compact('customers'));
    }

    /**
     * The shared filter + order block for the customer list and its CSV export.
     * Always ordered, with `id desc` as the FINAL key so pages never repeat or
     * skip rows on MySQL/PostgreSQL when the sort column ties.
     *
     * @return Builder<Customer>
     */
    private function customerQuery(Request $request): Builder
    {
        // Follow the sidebar store switcher; ?scope=all restores the owner-wide list.
        $activeStore = request('scope') === 'all' ? null : dashboard_current_store();
        $storeIds = $activeStore ? [(int) $activeStore->id] : dashboard_store_ids();

        $query = Customer::whereIn('store_id', $storeIds);

        // Search
        if ($request->filled('q')) {
            $query->whereSearch((string) $request->input('q')); // P1-17: Scout database search (name/email/phone)
        }

        if ($request->filled('segment')) {
            $this->applySegment($query, (string) $request->segment, $activeStore, $storeIds);
        } else {
            Sql::orderByDescNullsLast($query, 'last_order_at');
            $query->orderByDesc('id');
        }

        return $query;
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  list<int>  $storeIds
     */
    private function applySegment(Builder $query, string $segment, ?Store $activeStore, array $storeIds): void
    {
        if ($segment === 'new_this_month') {
            // "This month" on the selected store's clock (created_at is app-tz);
            // the all-stores view keeps the platform month.
            $monthStart = $activeStore
                ? Carbon::now(store_tz($activeStore->id))->startOfMonth()->setTimezone(config('app.timezone') ?: 'UTC')
                : now()->startOfMonth();
            $query->where('created_at', '>=', $monthStart)
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            return;
        }

        if ($segment === 'top_spenders') {
            $this->orderTopSpenders($query, $storeIds);

            return;
        }

        if ($segment === 'lapsed') {
            $query->where('last_order_at', '<', now()->subMonths(3))
                ->orderByDesc('last_order_at')
                ->orderByDesc('id');

            return;
        }

        if ($segment === 'consented') {
            $query->where('marketing_consent', 1)
                ->orderByDesc('id');
        }
    }

    /**
     * P1-05: `total_spent_minor` is only comparable within one currency. When the
     * selected stores span more than one currency (an all-stores top-spenders view),
     * rank by the currency-neutral `orders_count` instead; otherwise keep the minor
     * spend order. `id desc` breaks ties on every database.
     *
     * @param  Builder<Customer>  $query
     * @param  list<int>  $storeIds
     */
    private function orderTopSpenders(Builder $query, array $storeIds): void
    {
        $currencies = array_unique(array_map(
            static fn (int $storeId): string => store_currency($storeId),
            $storeIds
        ));

        if (count($currencies) > 1) {
            $query->orderByDesc('orders_count');
        } else {
            $query->orderByDesc('total_spent_minor');
        }

        $query->orderByDesc('id');
    }

    public function show(Request $request, Customer $customer)
    {
        $storeIds = dashboard_store_ids();

        if (! in_array($customer->store_id, $storeIds)) {
            abort(403);
        }

        $orders = Order::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $derivedSpend = $customer->derivedLifetimeSpend();   // M4

        return view('owner.customers.show', compact('customer', 'orders', 'derivedSpend'));
    }

    /**
     * M4: rewrite this customer's stored lifetime spend from their order history.
     * The checkout counter is atomic as of M4, but historic rows already carry the
     * damage from the old read-modify-write, and only the merchant can decide to
     * overwrite an LTV figure — so this is an explicit per-customer action, never a
     * background sweep. Audited: the previous value is recorded in activity_logs.
     */
    public function recalculate(Customer $customer): RedirectResponse
    {
        $storeIds = dashboard_store_ids();

        if (! in_array($customer->store_id, $storeIds)) {
            abort(403);
        }

        $before = (float) $customer->total_spent;
        $after = $customer->derivedLifetimeSpend();

        $customer->forceFill(['total_spent' => $after])->save();

        log_activity('customer.ltv_recalculated', $customer, [
            'from' => $before,
            'to' => $after,
        ], $customer->store_id);

        return back()->with('success', ___('Lifetime value recalculated from order history.'));
    }

    public function update(Request $request, Customer $customer)
    {
        $storeIds = dashboard_store_ids();

        if (! in_array($customer->store_id, $storeIds)) {
            abort(403);
        }

        $request->validate([
            'tags' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $customer->update([
            'tags' => $request->tags,
            'notes' => $request->notes,
        ]);

        return back()->with('success', ___('Customer updated successfully'));
    }

    public function export(Request $request)
    {
        $customers = $this->customerQuery($request)->get();

        $filename = 'customers_export_'.date('Ymd_His').'.csv';

        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$filename",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $columns = ['ID', 'Name', 'Email', 'Phone', 'Orders Count', 'Total Spent', 'Last Order At', 'Marketing Consent', 'Tags', 'Notes', 'Joined At'];

        $callback = function () use ($customers, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns, escape: '\\');

            foreach ($customers as $customer) {
                /* Name, tags and notes are attacker-controlled: a customer can
                   register as `=HYPERLINK(...)` and the owner opening this
                   export in Excel or Sheets executes it. Reuse the neutralizer
                   the backup exports already run every cell through, rather
                   than a second, weaker copy of the rule. */
                fputcsv($file, array_map(
                    [DatasetStreamWriter::class, 'csvCell'],
                    [
                        $customer->id,
                        $customer->name,
                        $customer->email,
                        $customer->phone,
                        $customer->orders_count,
                        $customer->total_spent,
                        $customer->last_order_at,
                        $customer->marketing_consent ? 'Yes' : 'No',
                        $customer->tags,
                        $customer->notes,
                        $customer->created_at,
                    ]
                ),
                    escape: '\\');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
