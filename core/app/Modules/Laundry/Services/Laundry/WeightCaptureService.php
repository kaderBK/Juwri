<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Services\Laundry;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Jobs\SendCustomerPushJob;
use Juwri\Modules\Ordering\Models\CustomerNotification;

/**
 * rev/multi-vertical Lane D — D4: weight-capture on pickup.
 *
 * A laundry line's requested weight (booked by the customer at checkout, D2)
 * is an estimate; the store re-weighs it at pickup and this service reprices
 * the line to the ACTUAL weight — updating the SAME `order_items.unit_price`
 * snapshot column {@see Order::computeMoney()} already treats as
 * the money authority (theme-totals-contract), so the order re-totals
 * correctly with zero changes to Order/OrderItem's shared pricing code.
 *
 * "Audit the change" reuses the platform's existing `log_activity()` seam
 * (app/Http/Helpers/Helper.php) — the same activity_logs table every other
 * dashboard write already logs to, not a new audit mechanism. "Customer
 * notified" reuses the existing customer-notification primitives
 * (CustomerNotification inbox row + SendCustomerPushJob), the same
 * best-effort, never-fail-the-write shape Juwri\Modules\Ordering\Services\CustomerPushService
 * uses for status pushes — this is a PRICE change, not a status change, so
 * that service's own status-keyed hook (Order::booted) does not fire for it.
 */
class WeightCaptureService
{
    /**
     * @return array{old_weight_kg: float, new_weight_kg: float, old_unit_price: ?float, new_unit_price: float}
     */
    public function capture(Order $order, OrderItem $item, float $newWeightKg): array
    {
        if ((int) $item->order_id !== (int) $order->id) {
            abort(404);
        }
        if ($item->weight_kg === null) {
            throw ValidationException::withMessages(['weight_kg' => ___('This line is not priced per kilogram.')]);
        }
        if ($newWeightKg <= 0) {
            throw ValidationException::withMessages(['weight_kg' => ___('Enter a weight greater than zero.')]);
        }

        $pricePerKg = $this->pricePerKg($item);
        $oldWeight = (float) $item->weight_kg;
        $oldUnitPrice = $item->unit_price !== null ? (float) $item->unit_price : null;
        $newUnitPrice = round($pricePerKg * $newWeightKg, 3);

        $item->weight_kg = round($newWeightKg, 3);
        $item->unit_price = $newUnitPrice;
        $item->save();

        // Re-derive subtotal/tax/grand now that this line's snapshot changed —
        // the SAME public re-snapshot Order already exposes (R1c), never a
        // parallel total computation.
        $order->unsetRelation('items');
        $order->snapshotTotals();

        log_activity('order.weight_captured', $item, [
            'order_id' => $order->id,
            'old_weight_kg' => $oldWeight,
            'new_weight_kg' => $item->weight_kg,
            'old_unit_price' => $oldUnitPrice,
            'new_unit_price' => $newUnitPrice,
        ], (int) $order->store_id);

        $this->notifyCustomer($order);

        return [
            'old_weight_kg' => $oldWeight,
            'new_weight_kg' => (float) $item->weight_kg,
            'old_unit_price' => $oldUnitPrice,
            'new_unit_price' => $newUnitPrice,
        ];
    }

    private function pricePerKg(OrderItem $item): float
    {
        if ($item->variation && $item->variant) {
            return (float) $item->variant->price;
        }

        return (float) ($item->menu->price ?? 0);
    }

    /**
     * Best-effort — a notification failure must never fail the weight capture
     * (same contract Juwri\Modules\Ordering\Services\CustomerPushService uses for status pushes).
     */
    private function notifyCustomer(Order $order): void
    {
        try {
            if ($order->customer_id) {
                $store = Store::where('id', $order->store_id)->value('title');
                CustomerNotification::create([
                    'customer_id' => (int) $order->customer_id,
                    'store_id' => (int) $order->store_id,
                    'order_id' => (int) $order->id,
                    'type' => 'order_weight_updated',
                    'title' => $store ?: ___('Order update'),
                    'body' => ___('We weighed your order and updated the total.'),
                    'url' => $order->track_token ? route('store.orderTrack', $order->track_token, false) : null,
                ]);
            }
            if ($order->track_token) {
                SendCustomerPushJob::dispatch((int) $order->id, (int) $order->store_id, 'weight_updated', false);
            }
        } catch (\Throwable $e) {
            Log::warning('[laundry-weight] customer notify failed: '.$e->getMessage());
        }
    }
}
