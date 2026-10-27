<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Http\Controllers\User;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Verticals\VerticalRegistry;
use App\Services\Verticals\VerticalVocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Laundry\Services\Laundry\ServiceBoardVocab;
use Juwri\Modules\Laundry\Services\Laundry\WeightCaptureService;
use Juwri\Modules\Verticals\Http\Controllers\User\RetailOrderBoardController;
use Juwri\Modules\Verticals\Services\Fulfillment\RetailOrderBoardService;

/**
 * rev/multi-vertical Lane D — D3 (service board) + D4 (weight capture).
 *
 * Its OWN dashboard page rather than a fork of
 * {@see RetailOrderBoardController} (Lane B's B5
 * file — this pack's hard rule is to consume Lane B's files, never edit
 * them): that controller's view hardcodes goods labels ("New"/"Packing"/...)
 * with no per-vertical override hook, so Lane D renders the SAME state
 * machine — {@see RetailOrderBoardService}, completely unmodified — through
 * its own view with the labels DECISIONS A9 asks for (received→processing→
 * ready→out_for_delivery→done; see Juwri\Modules\Laundry\Services\Laundry\ServiceBoardVocab).
 */
class LaundryServiceBoardController extends Controller
{
    use ResolvesCurrentStore;

    public function index(Request $request, VerticalVocab $vocab): View
    {
        $store = $this->currentRestaurant($request);
        abort_unless(app(VerticalRegistry::class)->has($store->store_type ?? null, 'service'), 404);

        $orders = Order::where('store_id', $store->id)
            ->whereIn('status', RetailOrderBoardService::STATES)
            ->with('items.menu')
            ->orderByDesc('id')
            ->get()
            ->groupBy('status');

        return view('owner.orders.service-board', [
            'store' => $store,
            'stateLabels' => ServiceBoardVocab::labels(),
            'boardLabel' => $vocab->forStore($store, 'board'),
            'states' => RetailOrderBoardService::STATES,
            'ordersByState' => $orders,
        ]);
    }

    public function advance(Request $request, Order $order, RetailOrderBoardService $board): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        abort_unless((int) $order->store_id === (int) $store->id, 404);

        $board->advance($order);

        return back()->with('success', ___('Order updated.'));
    }

    /** D4: dashboard weight-capture — enter the actual weight, reprice, audit, notify. */
    public function captureWeight(Request $request, Order $order, OrderItem $item, WeightCaptureService $service): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        abort_unless((int) $order->store_id === (int) $store->id, 404);

        $data = $request->validate([
            'weight_kg' => ['required', 'numeric', 'min:0.01', 'max:9999'],
        ]);

        $service->capture($order, $item, (float) $data['weight_kg']);

        return back()->with('success', ___('Weight updated — the order was repriced.'));
    }
}
