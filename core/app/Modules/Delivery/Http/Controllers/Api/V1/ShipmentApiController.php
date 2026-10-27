<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\CapsPerPage;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Api\V1\Concerns\OwnerScoped;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Delivery\Models\Shipment;

/**
 * rev/mv-surface-api P2 (mcp-fusion): read-only owner-token access to
 * parcel/shipment records for physical-goods orders (DECISIONS B4 — one
 * shipment row per order). Gated by `orders:read` + `feature:multi_vertical`
 * at the route — the SAME pairing as the MCP server's `list_shipments` tool
 * (OwnerTools::listShipments), and this controller's row() below
 * deliberately reuses its field names and date format (ISO 8601 string) so a
 * partner reads identical shipment data whether they call this REST endpoint
 * or the MCP tool. Shipment has no `store_id` of its own — it is
 * reached only through its parent Order (order.store_id), same fence
 * the dashboard's ShipmentController applies before any write.
 */
class ShipmentApiController extends Controller
{
    use CapsPerPage, OwnerScoped;

    /** GET /stores/{id}/shipments — every shipment for this store's orders. */
    public function index(Request $request, int $storeId): JsonResponse
    {
        $this->ownedRestaurant($request, $storeId);

        $shipments = Shipment::whereHas('order', fn ($q) => $q->where('store_id', $storeId))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 30));

        return response()->json($shipments->through(fn (Shipment $s) => $this->row($s)));
    }

    /** GET /stores/{id}/orders/{orderId}/shipment — the one shipment for this order. */
    public function show(Request $request, int $storeId, int $orderId): JsonResponse
    {
        $this->ownedRestaurant($request, $storeId);
        $order = Order::where('store_id', $storeId)->findOrFail($orderId);
        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();

        return response()->json($this->row($shipment));
    }

    /** @return array<string, mixed> */
    private function row(Shipment $s): array
    {
        return [
            'id' => $s->id, // REST-only identity field, additive to the MCP list_shipments shape below.
            // Same field names + date format (ISO 8601 string) as OwnerTools::listShipments().
            'order_id' => $s->order_id,
            'status' => $s->status,
            'carrier' => $s->carrier,
            'tracking_number' => $s->tracking_number,
            'shipped_at' => optional($s->shipped_at)->toIso8601String(),
            'delivered_at' => optional($s->delivered_at)->toIso8601String(),
        ];
    }
}
