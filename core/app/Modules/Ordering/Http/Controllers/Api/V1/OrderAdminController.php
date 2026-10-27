<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\CapsPerPage;
use App\Http\Controllers\Api\V1\Concerns\FormatsOrders;
use App\Models\Driver;
use App\Models\Order;
use App\Services\DeliveryService;
use App\Services\Marketplace\DispatchOrchestrator;
use App\Services\OrderApiService;
use App\Services\Verticals\FulfillmentRegistry;
use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Api\V1\Concerns\OwnerScoped;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Support\ClientOrderConflict;
use Juwri\Core\Support\ClientOrderId;

/**
 * Owner-token order management: create orders (powers a customer app driven by
 * the merchant's backend), read full detail, assign a driver, and drive the
 * delivery lifecycle.
 */
class OrderAdminController extends Controller
{
    use CapsPerPage, FormatsOrders, OwnerScoped;

    /** POST /stores/{id}/orders — create an order (write). */
    public function store(Request $request, int $storeId)
    {
        $store = $this->ownedRestaurant($request, $storeId);

        // rev/mv-surface-api P1: `type` was hardcoded to the food modes
        // (on-table/takeaway/delivery), so the API 422-rejected every non-food
        // fulfillment mode (ship/pickup_slot/appointment/in_store) for the other
        // 6 store types. Validate against what THIS store's vertical actually
        // allows (FulfillmentRegistry::modesFor), unioned with the legacy food
        // literals so an existing restaurant integration never breaks.
        $allowedTypes = array_values(array_unique(array_merge(
            ['on-table', 'takeaway', 'delivery'],
            app(FulfillmentRegistry::class)->modesFor($store->store_type)
        )));

        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string', Rule::in($allowedTypes)],
            'customer_name' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:40',
            'table_number' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:255',
            // P6-59b: body ids are scoped to THIS store — another tenant's branch or customer is a 422, never attached.
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $store->id)],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('store_id', $store->id)],
            'tip_amount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:1000',
            'source' => 'nullable|string|max:40',
            'client_uuid' => 'nullable|string|max:64', // A2: idempotency (body or Idempotency-Key header)
            'scheduled_at' => 'nullable|string|max:40', // T1: optional pre-order time
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            'items.*.variation' => 'nullable|integer',
            'items.*.extras' => 'nullable|array',
            'items.*.extras.*' => 'integer',
            'items.*.note' => 'nullable|string|max:255',
            // Coupons & Wallets P7-3a: owner coupon; store credit only applies when
            // a customer_id is attached (account-bound, D3 — enforced by the concern).
            'coupon_code' => 'nullable|string|max:64',
            'use_wallet' => 'nullable|boolean',
            'wallet_amount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            if ($errors->has('type')) {
                // Clear, actionable 422 instead of the generic "field is invalid" —
                // an integrator building against a new vertical needs to know
                // exactly which modes this store supports.
                return ApiError::status(
                    422,
                    'Invalid order type for this store. Allowed types: '.implode(', ', $allowedTypes).'.',
                    $errors->toArray()
                );
            }
            throw new ValidationException($validator);
        }

        $data = $validator->validated();

        // A2: integrators (Zapier, partner apps) send the standard Idempotency-Key
        // header rather than a body field — fold it in as the dedupe key.
        // J27-A2: the key (body client_order_id / client_uuid or the header) must be a UUID (v7 preferred, v4
        // accepted); a resend with the same cart returns the same order, another cart under the same key is a 409.
        $data['client_uuid'] = ClientOrderId::fromRequest($request);

        try {
            $order = OrderApiService::create($this->ownerId($request), $storeId, $data);
        } catch (\InvalidArgumentException $e) {
            return ApiError::status(422, $e->getMessage()); // T1: scheduling rule violation (A5-4 envelope)
        } catch (ClientOrderConflict $e) {
            return ApiError::status(409, $e->getMessage());
        }

        return response()->json($this->orderDetail($order), 201);
    }

    /** GET /stores/{id}/orders/{orderId} — full order detail. */
    public function show(Request $request, int $storeId, int $orderId)
    {
        $this->ownedRestaurant($request, $storeId);
        $order = Order::where('store_id', $storeId)->findOrFail($orderId);

        return response()->json($this->orderDetail($order));
    }

    /** GET /stores/{id}/deliveries — delivery orders, filterable. */
    public function deliveries(Request $request, int $storeId)
    {
        $this->ownedRestaurant($request, $storeId);

        $orders = Order::where('store_id', $storeId)
            ->where('type', 'delivery')
            ->when($request->query('delivery_status'), fn ($q, $s) => $q->where('delivery_status', $s))
            ->when($request->query('driver_id'), fn ($q, $d) => $q->where('driver_id', $d))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 30));

        return response()->json($orders->through(fn ($o) => $this->orderRow($o)));
    }

    /** POST /stores/{id}/orders/{orderId}/assign — assign a driver (write). */
    public function assignDriver(Request $request, int $storeId, int $orderId)
    {
        $this->ownedRestaurant($request, $storeId);
        $order = Order::where('store_id', $storeId)->findOrFail($orderId);

        $driverId = (int) $request->validate([
            'driver_id' => 'required|integer',
        ])['driver_id'];

        // The driver must belong to this owner (and, if scoped, this restaurant).
        $driver = Driver::where('owner_id', $this->ownerId($request))
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $storeId))
            ->findOrFail($driverId);

        // Route through the orchestrator so a reassignment releases the previously
        // assigned fleet driver's active_load. A bare setStatus('assigned') moved the
        // status and fired the webhook but never decremented the old fleet driver, so
        // reassigning a fleet-held order to a store driver leaked that capacity forever.
        $result = new DispatchOrchestrator()->reassign($order, $driver);
        if (! $result['ok']) {
            return response()->json(['error' => $result['error'] ?? 'assign_failed'], 422);
        }

        return response()->json($this->orderDetail($order->fresh()));
    }

    /**
     * PUT /stores/{id}/orders/{orderId}/delivery-status — set lifecycle (write).
     *
     * P6-354: this used to set any status on any order of the store. It now refuses
     * (422) a non-delivery order, `assigned` with no driver on the order (use the
     * assign endpoint), a backward move, any move out of delivered/failed, and
     * `delivered` while proof of delivery is still owed. The owner may override the
     * proof rule with `force=true`; that override is written to the activity log.
     */
    public function updateDeliveryStatus(Request $request, int $storeId, int $orderId)
    {
        $this->ownedRestaurant($request, $storeId);
        $order = Order::where('store_id', $storeId)->findOrFail($orderId);

        $data = $request->validate([
            'delivery_status' => 'required|in:'.implode(',', DeliveryService::STATUSES),
            'force' => 'sometimes|boolean',
        ]);
        $status = $data['delivery_status'];
        $force = (bool) ($data['force'] ?? false);
        $current = (string) $order->delivery_status;

        if ($order->type !== 'delivery') {
            return response()->json(['error' => 'not_a_delivery_order', 'message' => 'Only delivery orders have a delivery status.'], 422);
        }
        if (DeliveryService::isTerminal($current)) {
            return response()->json(['error' => 'delivery_finished', 'message' => 'This delivery is already '.$current.'.'], 422);
        }
        if ($status === 'assigned' && empty($order->driver_id)) {
            return response()->json(['error' => 'driver_required', 'message' => 'Assign a driver with the assign-driver endpoint first.'], 422);
        }
        if (DeliveryService::isBackward($current, $status)) {
            return response()->json(['error' => 'backward_move', 'message' => 'A delivery cannot be moved backwards.'], 422);
        }

        $podOverride = false;
        if ($status === 'delivered' && ! DeliveryService::podSatisfied($order)) {
            if (! $force) {
                return response()->json([
                    'error' => 'proof_of_delivery_required',
                    'message' => 'Proof of delivery required. Send force=true to override.',
                    'pod_mode' => DeliveryService::podMode((int) $order->store_id),
                ], 422);
            }
            $podOverride = true;
        }

        $order = DeliveryService::setStatus($order, $status);

        // A racing update (driver app, courier webhook) reached a terminal status first:
        // setStatus ignored ours under the row lock. Say so instead of answering 200.
        if ((string) $order->delivery_status !== $status) {
            return response()->json(['error' => 'delivery_finished', 'message' => 'This delivery is already '.$order->delivery_status.'.'], 409);
        }

        if ($podOverride) {
            log_activity('order.delivery_pod_override', $order, [
                'api_user_id' => $this->ownerId($request),
                'pod_mode' => DeliveryService::podMode((int) $order->store_id),
                'previous_status' => $current,
            ], $storeId);
        }

        return response()->json($this->orderDetail($order));
    }
}
