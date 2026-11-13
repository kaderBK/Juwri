<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use App\Models\Order;
use App\Services\Verticals\VerticalVocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Verticals\Services\Fulfillment\RetailOrderBoardService;

/**
 * rev/multi-vertical B5 — the retail/laundry order board, relabeled per
 * vertical via VerticalVocab (never "kitchen" — that word, and the KDS
 * screen it names, stays food-only and untouched by this controller).
 */
class RetailOrderBoardController extends Controller
{
    use ResolvesCurrentStore;

    public function index(Request $request, VerticalVocab $vocab): View
    {
        $store = $this->currentRestaurant($request);

        $orders = Order::where('store_id', $store->id)
            ->whereIn('status', RetailOrderBoardService::STATES)
            ->orderByDesc('id')
            ->get()
            ->groupBy('status');

        return view('owner.orders.board', [
            'store' => $store,
            'boardLabel' => $vocab->forStore($store, 'board'),
            'states' => RetailOrderBoardService::STATES,
            'ordersByState' => $orders,
        ]);
    }

    public function advance(Request $request, Order $order, RetailOrderBoardService $board): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        // G2 tenancy fence: the order must belong to the store the owner is acting on.
        abort_unless((int) $order->store_id === (int) $store->id, 404);

        $board->advance($order);

        return back()->with('success', ___('Order updated.'));
    }
}
