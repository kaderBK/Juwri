<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Http\Controllers;

use Juwri\Modules\Delivery\Models\CourierDelivery;
use Juwri\Core\Models\Credential;
use App\Models\Order;
use App\Services\DeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Contracts\Webhooks\WebhookDeduplication;
use Juwri\Core\Http\Controllers\Controller;

/**
 * T4-P2 inbound courier status webhooks (Uber Direct, DoorDash Drive). Verifies the
 * provider HMAC signature against the restaurant's BYOK secret, updates the tracked
 * CourierDelivery, mirrors the status onto the order's delivery_status, and stores the
 * latest courier GPS. Uber retries only 3× so a poll reconciliation job is the backstop.
 */
class CourierWebhookController extends Controller
{
    /** Provider status → local courier/order status. */
    private const MAP = [
        'created' => ['courier' => 'created', 'order' => 'assigned'],
        'pending' => ['courier' => 'created', 'order' => 'assigned'],
        'pickup' => ['courier' => 'picked_up', 'order' => 'picked_up'],
        'pickup_complete' => ['courier' => 'picked_up', 'order' => 'out_for_delivery'],
        'dropoff' => ['courier' => 'picked_up', 'order' => 'out_for_delivery'],
        'delivered' => ['courier' => 'delivered', 'order' => 'delivered'],
        'canceled' => ['courier' => 'canceled', 'order' => 'failed'],
    ];

    /** P6-100: courier status order; a status only ever moves to a higher rank. */
    private const COURIER_RANK = ['pending' => 0, 'created' => 1, 'picked_up' => 2, 'delivered' => 3, 'canceled' => 3];

    private const TERMINAL = ['delivered', 'canceled'];

    /** P6-100: order delivery_status order for courier-driven updates (delivered/failed final, see DeliveryService). */
    private const ORDER_RANK = ['' => 0, 'pending' => 0, 'unassigned' => 0, 'assigned' => 1, 'picked_up' => 2,
        'out_for_delivery' => 3, 'delivered' => 4, 'failed' => 4];

    public function inbound(Request $request, string $provider)
    {
        if (!in_array($provider, ['uber_direct', 'doordash'], true)) {
            return response()->json(['error' => 'Unknown courier.'], 404);
        }

        $payload = $request->json()->all() ?: $request->all();
        $externalId = (string) ($payload['delivery_id'] ?? $payload['external_delivery_id'] ?? $payload['id'] ?? '');
        if ($externalId === '') {
            return response()->json(['error' => 'Missing delivery id.'], 422);
        }

        $delivery = CourierDelivery::where('provider', $provider)->where('external_delivery_id', $externalId)->first();
        if (!$delivery) {
            return response()->json(['error' => 'Unknown delivery.'], 404);
        }

        // Verify signature with the restaurant's stored secret.
        $creds = Credential::for($provider, (int) $delivery->store_id) ?? [];
        $secret = $creds['client_secret'] ?? $creds['signing_secret'] ?? null;
        $header = (string) $request->header($provider === 'uber_direct' ? 'X-Uber-Signature' : 'X-DoorDash-Signature', '');
        if (!$secret || $header === '' || !hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $header)) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        // P6-100: a re-delivered (identical, signed) event is a no-op; the signed body is the event identity.
        return app(WebhookDeduplication::class)->once(
            'courier_'.$provider,
            $delivery->id.':'.hash('sha256', $request->getContent()),
            fn () => $this->apply($delivery, $payload),
            fn () => response()->json(['success' => true, 'duplicate' => true]),
        );
    }

    /**
     * P6-100: apply a verified event forward-only. Couriers retry and do not guarantee order, so a late "pickup"
     * after "delivered" must not move the delivery (or the order) backwards, and a late GPS fix must not overwrite a
     * newer one. Status moves only to a higher rank (one conditional UPDATE, so two racing events cannot both win);
     * terminal states are final; a location is kept only when its event time is newer than the stored fix.
     *
     * @param  array<string, mixed>  $payload
     */
    private function apply(CourierDelivery $delivery, array $payload): JsonResponse
    {
        $status = strtolower((string) ($payload['status'] ?? ''));
        $map = self::MAP[$status] ?? null;

        if ($map) {
            $lower = array_keys(array_filter(self::COURIER_RANK, fn (int $rank) => $rank < self::COURIER_RANK[$map['courier']]));
            CourierDelivery::query()->whereKey($delivery->getKey())
                ->where(fn ($q) => $q->whereIn('status', $lower)->orWhereNull('status')->orWhereNotIn('status', array_keys(self::COURIER_RANK)))
                ->update(['status' => $map['courier'], 'updated_at' => now()]);
        }

        if (isset($payload['courier']['location']) && is_array($payload['courier']['location'])) {
            $loc = $payload['courier']['location'];
            $at = $this->eventTime($payload) ?? now();
            CourierDelivery::query()->whereKey($delivery->getKey())
                ->whereNotIn('status', self::TERMINAL)
                ->where(fn ($q) => $q->whereNull('courier_updated_at')->orWhere('courier_updated_at', '<', $at))
                ->update([
                    'courier_lat' => is_numeric($loc['lat'] ?? null) ? (float) $loc['lat'] : null,
                    'courier_lng' => is_numeric($loc['lng'] ?? null) ? (float) $loc['lng'] : null,
                    'courier_updated_at' => $at,
                    'updated_at' => now(),
                ]);
        }

        // Mirror onto the order's delivery lifecycle (own-fleet + 3rd-party share it), forward-only as well.
        if ($map && ($order = Order::find($delivery->order_id))) {
            $current = self::ORDER_RANK[(string) $order->delivery_status] ?? 0;
            if (self::ORDER_RANK[$map['order']] > $current) {
                DeliveryService::setStatus($order, $map['order']);
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * The provider's signed event time (Uber `created`, DoorDash `created_at`), null when absent/unparseable.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eventTime(array $payload): ?CarbonImmutable
    {
        foreach (['created', 'created_at', 'updated_at', 'timestamp', 'event_time'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return CarbonImmutable::createFromTimestampUTC((int) $value);
            }
            if (is_string($value) && $value !== '') {
                try {
                    return CarbonImmutable::parse($value)->utc();
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
}
