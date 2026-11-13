<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Order;
use App\Services\Verticals\FulfillmentRegistry;
use App\Support\DriverExposure;
use Illuminate\Support\Facades\URL;
use Juwri\Modules\Ordering\Services\Orders\EtaEstimator;

trait FormatsOrders
{
    /** Compact order row for list endpoints. */
    protected function orderRow(Order $o): array
    {
        $row = [
            'id' => $o->id,
            'status' => $o->status,
            'type' => $o->type,
            'delivery_status' => $o->delivery_status,
            'table_number' => $o->table_number,
            'customer_name' => $o->customer_name,
            'total' => $o->total,
            'currency' => $o->currencyCode(), // MC-13: order's stamped currency
            'is_paid' => (bool) $o->is_paid,
            'driver_id' => $o->driver_id,
            'created_at' => $o->created_at,
        ];

        // rev/mv-surface-api P1: expose the store's vertical, but only when the
        // caller already eager-loaded `restaurant` — polled list endpoints
        // (fleet/customer apps) must not take an N+1. orderDetail() loadMissing's
        // it, so single-order reads always carry store_type.
        if ($o->relationLoaded('store') && $o->store) {
            $row['store_type'] = $o->store->store_type;
        }

        return $row;
    }

    /** Full order with line items, for detail + create responses. */
    protected function orderDetail(Order $o): array
    {
        $o->loadMissing('items.menu', 'items.itemExtras.extra', 'items.variant', 'driver', 'voiceNote', 'store', 'shipment');

        return array_merge($this->orderRow($o), [
            // rev/mv-surface-api P1: the human fulfillment-mode label (Ship /
            // Pickup / Delivery…) and the goods shipment, so a non-food order
            // reads correctly instead of as a bare food order.
            'type_label' => app(FulfillmentRegistry::class)->label((string) $o->type),
            'shipment' => $o->shipment ? [
                'status' => $o->shipment->status,
                'carrier' => $o->shipment->carrier,
                'tracking_number' => $o->shipment->tracking_number,
                'shipped_at' => $o->shipment->shipped_at,
                'delivered_at' => $o->shipment->delivered_at,
            ] : null,
            'phone_number' => $o->phone_number,
            'address' => $o->address,
            'branch_id' => $o->branch_id,
            'tip_amount' => $o->tip_amount,
            'note' => $o->message,
            // FEATURES-5 Track C: short-lived signed URL the PWA can feed an <audio> element.
            'voice_note_url' => $o->voiceNote
                ? URL::temporarySignedRoute('voiceNote.signedStream', now()->addHours(6), ['order' => $o->id])
                : null,
            'voice_note_duration' => $o->voiceNote?->duration_seconds,
            // ZERO-TOUCH Track B3: auto-transcribed text (null until/unless filled).
            'voice_note_transcript' => $o->voiceNote?->transcript,
            'track_token' => $o->track_token,
            // ZERO-TOUCH Track C: informational prep-time estimate in minutes
            // ("20-30"); null once the order is ready or when no estimate exists.
            'prep_eta' => EtaEstimator::forOrder($o)?->toApiString(),
            'assigned_at' => $o->assigned_at,
            'picked_up_at' => $o->picked_up_at,
            'out_for_delivery_at' => $o->out_for_delivery_at,
            'delivered_at' => $o->delivered_at,
            'driver' => $o->driver ? [
                'id' => $o->driver->id,
                'name' => $o->driver->name,
                // P6-236: the driver's phone only while the delivery is live.
                'phone' => DriverExposure::isLive($o) ? $o->driver->phone : null,
            ] : null,
            'items' => $o->items->map(fn ($it) => [
                'id' => $it->id,
                'item_id' => $it->item_id,
                'name' => $it->menu?->getRawOriginal('name'),
                'quantity' => $it->quantity,
                // WV.V: a snapshotted T5 schedule price REPLACES the derived one in
                // Order::getTotalAttribute(); deriving it here made the API's line
                // prices disagree with the order total it ships alongside them.
                'unit_price' => $it->unit_price ?? $it->variant->price ?? $it->menu?->price,
                'variation' => $it->variation ?: null,
                'variant' => $it->variant?->name,
                'extras' => $it->itemExtras->map(fn ($e) => [
                    'id' => $e->extra_id,
                    'name' => $e->extra?->name,
                    'price' => $e->extra?->price,
                ])->values()->all(),
                'note' => $it->order_note,
            ])->values()->all(),
        ]);
    }
}
