<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Http\Controllers\User;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Delivery\Models\Shipment;
use Juwri\Modules\Ordering\Models\ShippingMethod;

/**
 * rev/multi-vertical B4 — the shipment/parcel concept.
 *
 * Dashboard: mark an order shipped with a tracking number/carrier (creates
 * the one-per-order Shipment row, and advances the B5 board past "ready" so
 * the two features tell a consistent story). Storefront: an anonymous,
 * track-token-scoped read of that same status — the SAME auth model the
 * existing generic order-track page uses, so no new tenancy surface is
 * introduced.
 */
class ShipmentController extends Controller
{
    use ResolvesCurrentStore;

    public function update(Request $request, Order $order): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        abort_unless((int) $order->store_id === (int) $store->id, 404);

        $data = $request->validate([
            'carrier' => 'nullable|string|max:100',
            'tracking_number' => 'nullable|string|max:191',
        ]);

        $shipment = Shipment::firstOrCreate(['order_id' => $order->id]);
        // P3-08: the order's shipping method names the carrier (when none was typed) and builds the tracking link.
        $method = $order->shipping_method_id ? ShippingMethod::query()->where('store_id', $order->store_id)->find($order->shipping_method_id) : null;
        $carrier = ($data['carrier'] ?? null) ?: $method?->carrier;
        $shipment->shipping_method_id = $method?->id;
        $shipment->tracking_url = $method?->trackingUrl($data['tracking_number'] ?? null);
        $shipment->markShipped($carrier, $data['tracking_number'] ?? null);

        // B5 tie-in: shipping an order that hasn't reached the "shipped" board
        // state yet fast-forwards it there, so the two screens never disagree.
        if (in_array($order->status, ['new', 'packing', 'ready'], true)) {
            $order->status = 'shipped';
            $order->shipped_at = now();
            $order->save();
        }

        return back()->with('success', ___('Marked as shipped.'));
    }

    /** GET order/track/{token}/shipment — anonymous, token-scoped (same model as trackOrder). */
    public function publicStatus(string $token): JsonResponse
    {
        $order = Order::where('track_token', $token)->firstOrFail();
        $shipment = Shipment::where('order_id', $order->id)->first();

        return response()->json([
            'status' => $shipment->status ?? 'pending',
            'carrier' => $shipment->carrier ?? null,
            'tracking_number' => $shipment->tracking_number ?? null,
            'tracking_url' => $shipment->tracking_url ?? null,
            'shipped_at' => optional($shipment?->shipped_at)->toIso8601String(),
        ]);
    }
}
