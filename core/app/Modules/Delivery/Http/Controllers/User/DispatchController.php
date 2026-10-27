<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Http\Controllers\User;

use App\Models\Driver;
use App\Models\Order;
use App\Services\Marketplace\DispatchOrchestrator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Delivery\Services\Couriers\DispatchService;
use Juwri\Modules\Delivery\Services\Delivery\DeliveryReportService;

/**
 * T4-P4 dispatch screen: pending delivery orders with a side-by-side courier quote
 * comparison (own fleet vs Uber Direct vs DoorDash), plus one-click dispatch. Tenant-scoped.
 */
class DispatchController extends Controller
{
    private function storeIds(): array
    {
        return dashboard_store_ids();
    }

    private function currentRestaurantId(Request $request): int
    {
        $ids = $this->storeIds();
        $rid = (int) ($request->query('store_id') ?: (dashboard_current_store()->id ?? $ids[0] ?? 0));
        abort_unless(in_array($rid, $ids, true), 403);

        return $rid;
    }

    private function ownedOrder(int $id): Order
    {
        return Order::whereIn('store_id', $this->storeIds())->findOrFail($id);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        $orders = Order::where('store_id', $rid)
            ->where('type', 'delivery')
            ->whereIn('delivery_status', ['pending', 'assigned'])
            ->latest('id')->limit(50)->get();

        $quotes = [];
        foreach ($orders as $order) {
            $quotes[$order->id] = DispatchService::quoteAll($order);
        }

        return view('owner.dispatch.index', [
            'rid' => $rid,
            'orders' => $orders,
            'quotes' => $quotes,
            'mode' => store_options($rid, 'dispatch_mode') ?: 'manual',
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function dispatchOrder(Request $request, int $id)
    {
        $order = $this->ownedOrder($id);
        $provider = (string) $request->input('provider', 'own');
        $delivery = DispatchService::dispatch($order, $provider);

        app_alert_success($delivery
            ? ___('Dispatched to').' '.ucwords(str_replace('_', ' ', $provider))
            : ___('Order queued for your own drivers.'));

        return back();
    }

    /** SYSTEMS-RUN E-P2: live dispatcher map (drivers + active deliveries). */
    public function map(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        return view('owner.dispatch.map', [
            'rid' => $rid,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    /** JSON feed the map polls: driver markers + active delivery lines + suggestions. */
    public function mapFeed(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $svc = new \Juwri\Modules\Delivery\Services\Delivery\DispatchService;

        $drivers = Driver::where('store_id', $rid)->where('active', true)->get()
            ->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name,
                'lat' => $d->last_lat !== null ? (float) $d->last_lat : null,
                'lng' => $d->last_lng !== null ? (float) $d->last_lng : null,
                'stale' => $d->last_location_at ? Carbon::parse($d->last_location_at)->lt(now()->subMinutes(5)) : true,
            ]);

        $active = Order::where('store_id', $rid)->where('type', 'delivery')
            ->whereNotIn('delivery_status', ['delivered', 'failed'])->latest('id')->limit(50)->get();

        $unassigned = $active->whereNull('driver_id')->map(fn ($o) => [
            'id' => $o->id, 'address' => $o->address,
            'suggestions' => collect($svc->suggestDrivers($o, 3))->map(fn ($s) => [
                'driver_id' => $s['driver']->id, 'name' => $s['driver']->name, 'distance_km' => $s['distance'],
                // P6-344: stale = last_location_at older than GPS_FRESH_MINUTES
                // (or null). The view uses it to render a "GPS old" tag.
                'stale' => (bool) ($s['stale'] ?? false),
            ])->values(),
        ])->values();

        return response()->json([
            'drivers' => $drivers->values(),
            'active' => $active->map(fn ($o) => ['id' => $o->id, 'driver_id' => $o->driver_id, 'status' => $o->delivery_status])->values(),
            'unassigned' => $unassigned,
        ]);
    }

    /** Assign a driver to an order (from the map's suggestion panel). */
    public function assign(Request $request, int $id)
    {
        $order = $this->ownedOrder($id);
        $data = $request->validate(['driver_id' => 'required|integer']);
        $driver = Driver::where('store_id', $order->store_id)->whereKey($data['driver_id'])->first();
        abort_unless($driver !== null, 422);

        // Route through the orchestrator, not a raw driver_id write: it refuses a
        // terminal order, releases a previously-assigned fleet driver's active_load
        // (a raw overwrite leaked it forever, permanently shrinking that driver's
        // capacity), moves delivery_status to 'assigned' + fires order.driver_assigned,
        // and cancels stale pending offers — everything the blind update skipped.
        $result = new DispatchOrchestrator()->reassign($order, $driver);
        if (! $result['ok']) {
            return back()->with('error', ___('Could not assign that driver to this order.'));
        }

        return back()->with('success', ___('Driver assigned.'));
    }

    /** Per-driver delivery report. */
    public function report(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $from = $request->query('from');
        $to = $request->query('to');

        return view('owner.dispatch.report', [
            'rid' => $rid,
            'rows' => new DeliveryReportService()->perDriver($rid, $from, $to),
            'from' => $from,
            'to' => $to,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }
}
