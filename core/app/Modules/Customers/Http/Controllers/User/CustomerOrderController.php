<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\User;

use App\Models\Order;
use App\Services\FeatureAccess;
use App\Services\WebhookFanout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Juwri\Core\Http\Controllers\Controller;

/**
 * Customer order-history reads for a single storefront (rev/multi-vertical).
 *
 * Behind auth:customer. Every query is scoped to the authenticated customer's id
 * AND the {storeId} in the path — a shopper only ever sees their own orders
 * at this store. Guests get an empty list + 401 (the theme then shows a sign-in
 * prompt). Read-only: this endpoint never mutates an order; "reorder" is done
 * client-side by re-adding the returned item ids to the cart.
 */
class CustomerOrderController extends Controller
{
    private const LIST_LIMIT = 30;

    public function index(Request $request, int|string $storeId): JsonResponse
    {
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return response()->json(['orders' => []], 401);
        }

        $orders = Order::where('customer_id', $customer->id)
            ->where('store_id', (int) $storeId)
            ->with('items:id,order_id,item_id,quantity')
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        // Refund requests only make sense where the wallet (store credit) exists.
        $walletOn = FeatureAccess::storeAllows((int) $storeId, 'customer_wallet');
        $window = (int) (store_options((int) $storeId, 'refund_request_window_days') ?? 14);

        return response()->json([
            'orders' => $orders->map(function (Order $o) use ($walletOn, $window) {
                $items = $o->items;
                $elig = $o->customerRefundEligibility($window);

                return [
                    'id' => (int) $o->id,
                    'number' => '#'.$o->id,
                    'status' => (string) $o->status,
                    'paid' => (bool) $o->is_paid,
                    'total' => price_symbol_format($o->total),
                    'itemCount' => (int) $items->sum('quantity'),
                    'itemIds' => $items->pluck('item_id')->filter()->values()->all(),
                    'at' => optional($o->created_at)->toIso8601String(),
                    'trackUrl' => $o->track_token ? route('store.orderTrack', $o->track_token) : null,
                    'canRequestRefund' => $walletOn && $elig['eligible'],
                    'refundStatus' => $o->refund_request_status, // null|requested|approved|rejected
                    'refundMethod' => $o->refund_method, // null|store_credit|original_payment
                ];
            })->all(),
        ]);
    }

    /**
     * A diner asks for a refund on one of their paid orders. Records the request
     * (state only — no money moves here); the owner reviews it and, on approval,
     * refunds to store credit via refundOrderToCredit. Scoped to the authed
     * customer + this store, gated on customer_wallet + the store's refund window.
     */
    public function requestRefund(Request $request, int|string $storeId, int|string $order): JsonResponse
    {
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return response()->json(['success' => false, 'message' => ___('Please sign in.')], 401);
        }

        $order = Order::where('id', (int) $order)
            ->where('customer_id', $customer->id)
            ->where('store_id', (int) $storeId)
            ->first();
        if (! $order) {
            return response()->json(['success' => false, 'message' => ___('Order not found.')], 404);
        }

        if (! FeatureAccess::storeAllows((int) $storeId, 'customer_wallet')) {
            return response()->json(['success' => false, 'message' => ___('Refunds to store credit are not available here.')], 422);
        }

        $window = (int) (store_options((int) $storeId, 'refund_request_window_days') ?? 14);
        $elig = $order->customerRefundEligibility($window);
        if (! $elig['eligible']) {
            return response()->json(['success' => false, 'reason' => $elig['reason'], 'message' => ___('This order is not eligible for a refund request.')], 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $order->forceFill([
            'refund_requested_at' => now(),
            'refund_request_status' => 'requested',
            'refund_request_reason' => $data['reason'] ?? null,
        ])->save();

        log_activity('order.refund_requested', $order, ['order_id' => $order->id], $order->store_id);
        WebhookFanout::forRestaurant((int) $order->store_id, 'refund.requested', [
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json(['success' => true, 'message' => ___('Refund request sent. The store will review it shortly.')]);
    }
}
