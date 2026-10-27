<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\CapsPerPage;
use App\Http\Controllers\Api\V1\Concerns\FormatsOrders;
use App\Models\Driver;
use App\Models\MkDispatchOffer;
use App\Models\Order;
use App\Services\DeliveryService;
use App\Services\Marketplace\DispatchOrchestrator;
use App\Services\Marketplace\FleetRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\Tenancy\TenantStorage;
use Juwri\Modules\Delivery\Http\Middleware\DriverTokenAuth;
use Juwri\Modules\Delivery\Services\Delivery\PodService;
use Juwri\Modules\Messaging\Contracts\MobilePush;
use Juwri\Modules\Messaging\Models\PushSubscription;

/**
 * Driver-app endpoints, authenticated by {@see DriverTokenAuth}.
 * Every query is scoped to the authenticated driver — a driver only ever sees or
 * touches their own assigned deliveries.
 */
class DriverAppController extends Controller
{
    use CapsPerPage;
    use FormatsOrders;

    /** Statuses a driver may set themselves (assignment is owner-only). */
    private const DRIVER_STATUSES = ['picked_up', 'out_for_delivery', 'delivered', 'failed'];

    private function driver(): Driver
    {
        return app('api.driver');
    }

    /** GET /driver/me */
    public function me()
    {
        // T4-3: shift/fleet fields are additive — existing consumers unaffected.
        return response()->json($this->driver()->toApiArray() + [
            'is_fleet' => (bool) $this->driver()->is_fleet,
            'shift_status' => $this->driver()->shift_status,
        ]);
    }

    /** GET /driver/deliveries — assigned orders; `?active=1` hides finished ones. */
    public function deliveries(Request $request)
    {
        // Perf: this list is polled (fleet app every ~10s). orderDetail() does a
        // per-order loadMissing() of the item graph + $o->total; eager-load the whole
        // page once so it stays a handful of queries instead of ~5 per order.
        $query = Order::where('driver_id', $this->driver()->id)
            ->with(['items.menu', 'items.variant', 'items.itemExtras.extra', 'driver', 'voiceNote']);

        if ($request->boolean('active')) {
            $query->whereNotIn('delivery_status', ['delivered', 'failed']);
        }

        $orders = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($request, 30));

        return response()->json($orders->through(fn ($o) => $this->orderDetail($o)));
    }

    /** GET /driver/available — unassigned delivery orders the driver may claim (owner opt-in). */
    public function available(Request $request)
    {
        $driver = $this->driver();
        if (! $this->selfClaimEnabled($driver)) {
            return response()->json(['data' => []]);
        }

        $orders = $this->claimable(Order::where('store_id', $driver->store_id))
            // a new delivery order has NO delivery status yet (NULL), and NULL NOT IN (...) is never true: keep those
            ->where(fn ($q) => $q->whereNull('delivery_status')->orWhereNotIn('delivery_status', ['delivered', 'failed']))
            // Perf: orderDetail() loadMissing()s the item graph per row; eager-load once.
            ->with(['items.menu', 'items.variant', 'items.itemExtras.extra', 'driver', 'voiceNote'])
            ->orderByDesc('created_at')->limit(30)->get();

        return response()->json(['data' => $orders->map(fn ($o) => $this->orderDetail($o))]);
    }

    /** POST /driver/deliveries/{orderId}/claim — self-assign an unassigned order (atomic). */
    public function claim(Request $request, int $orderId)
    {
        $driver = $this->driver();
        abort_unless($this->selfClaimEnabled($driver), 403);

        // an order of another store (or a pickup order) is not here for this driver at all
        abort_unless(Order::where('id', $orderId)->where('store_id', $driver->store_id)->where('type', 'delivery')->exists(), 404);

        // P6-235: the conditional UPDATE is the row lock; the dispatchable filter is part of it,
        // so a cancelled / unpaid / still-scheduled order cannot be claimed.
        $claimed = $this->claimable(Order::where('id', $orderId)->where('store_id', $driver->store_id))
            ->update(['driver_id' => $driver->id, 'assigned_at' => now(), 'delivery_status' => 'assigned']);

        if (! $claimed) {
            return response()->json(['error' => 'Order already taken.'], 409);
        }

        return response()->json($this->orderDetail(Order::find($orderId)));
    }

    /**
     * P6-235: delivery orders a driver may list/claim: unassigned, not cancelled/closed/awaiting
     * approval, not a still-scheduled future order, and paid or pay-on-delivery (cash/COD/none).
     */
    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function claimable(Builder $query): Builder
    {
        return $query->where('type', 'delivery')
            ->whereNull('driver_id')
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['cancelled', 'rejected', 'pending_approval', 'completed', 'delivered']))
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhereNotNull('released_at'))
            ->where(fn ($q) => $q->where('is_paid', 1)
                ->orWhereNull('payment_method')
                ->orWhereIn('payment_method', ['', 'cash', 'cod']));
    }

    private function selfClaimEnabled(Driver $driver): bool
    {
        return (bool) store_options($driver->store_id, 'driver_self_claim', 0);
    }

    /** PUT /driver/deliveries/{orderId}/status */
    public function updateStatus(Request $request, int $orderId)
    {
        $order = Order::where('driver_id', $this->driver()->id)->findOrFail($orderId);

        // P6-312: an offline outbox may replay a tap; a repeated Idempotency-Key is answered
        // with the current order and never re-applies the transition or re-fires events.
        $idemKey = substr((string) $request->header('Idempotency-Key', ''), 0, 100);
        $idemCacheKey = 'driver-status-idem:'.$this->driver()->id.':'.$orderId.':'.hash('sha256', $idemKey);
        if ($idemKey !== '' && Cache::has($idemCacheKey)) {
            return response()->json($this->orderDetail($order));
        }

        $status = $request->validate([
            'delivery_status' => 'required|in:'.implode(',', self::DRIVER_STATUSES),
            'note' => 'nullable|string|max:255',
        ]);

        // Forward-only for a driver-initiated update. A retried or out-of-order tap
        // must not regress the delivery (out_for_delivery → picked_up), which would
        // re-stamp timestamps and re-fire the entered-status webhook. Admin reassign/
        // unassign may move an order backward on purpose and go through their own
        // paths; a driver may only advance their own delivery. setStatus itself stays
        // unconstrained so those admin overrides keep working.
        $rank = ['pending' => 0, 'assigned' => 1, 'picked_up' => 2, 'out_for_delivery' => 3, 'delivered' => 4, 'failed' => 4];
        $current = $rank[(string) $order->delivery_status] ?? 0;
        if (($rank[$status['delivery_status']] ?? 0) < $current) {
            return response()->json(['error' => 'A delivery cannot be moved backwards.'], 422);
        }

        // SYSTEMS-RUN E-P2: block a "delivered" transition until POD is satisfied.
        if ($status['delivery_status'] === 'delivered' && ! PodService::satisfied($order)) {
            return response()->json(['error' => 'Proof of delivery required.', 'pod_mode' => PodService::mode((int) $order->store_id)], 422);
        }

        if (! empty($status['note'])) {
            $order->driver_note = $status['note'];
        }
        $order = DeliveryService::setStatus($order, $status['delivery_status']);
        if ($idemKey !== '') {
            Cache::put($idemCacheKey, 1, now()->addDay());
        }

        return response()->json($this->orderDetail($order));
    }

    /** POST /driver/deliveries/{orderId}/pod-otp — verify the customer's OTP. */
    public function podOtp(Request $request, int $orderId)
    {
        $order = Order::where('driver_id', $this->driver()->id)->findOrFail($orderId);
        $data = $request->validate(['otp' => 'required|string|max:8']);
        if (PodService::locked($order)) {
            return response()->json(['error' => 'Too many wrong codes. Try again later.'], 429);
        }
        if (! PodService::verifyOtp($order, $data['otp'])) {
            return response()->json(['error' => 'OTP does not match.'], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** POST /driver/deliveries/{orderId}/pod-photo — store a proof photo. */
    public function podPhoto(Request $request, int $orderId)
    {
        $order = Order::where('driver_id', $this->driver()->id)->findOrFail($orderId);
        $data = $request->validate(['photo' => 'required|file|mimes:jpg,jpeg,png|max:5120']);
        // P1-04 slice 7: new photos under the store's account prefix (drivers are not a tenant context).
        $path = $data['photo']->store(TenantStorage::dir('pod/'.$order->store_id, (int) $order->account_id), 'public');
        PodService::storePhoto($order, $path);

        return response()->json(['ok' => true, 'path' => $path]);
    }

    /* ------------------------------------------------------------------
     * Marketplace T4-3: fleet driver-app endpoints. Offers are only ever
     * created by DispatchOrchestrator (T4-2); accept/reject delegate to it —
     * this controller never assigns an order directly.
     * ------------------------------------------------------------------ */

    /** GET /driver/offers — pending dispatch offers for me (fleet drivers). */
    public function offers(DispatchOrchestrator $orchestrator)
    {
        $orchestrator->expireDueOffers(); // lazy sweep, no cron dependency

        // Perf: polled (~10s). Eager-load the order's store + item graph so the store
        // title is not a per-offer Post query and $o->order->total (which walks
        // items→menu/variant/itemExtras.extra) does not lazy-load per offer.
        $offers = MkDispatchOffer::where('driver_id', $this->driver()->id)
            ->pending()
            ->with(['order.store:id,title', 'order.items.menu', 'order.items.variant', 'order.items.itemExtras.extra'])
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (MkDispatchOffer $o) => $o->order !== null)
            ->map(fn (MkDispatchOffer $o) => [
                'id' => $o->id,
                'order_id' => $o->order_id,
                'expires_at' => $o->expires_at?->toIso8601String(),
                'store' => $o->order->store?->title,
                'address' => $o->order->address,
                'total' => $o->order->total,
            ])->values();

        return response()->json(['data' => $offers]);
    }

    /** POST /driver/offers/{id}/accept — accept via the dispatch orchestrator. */
    public function acceptOffer(int $offerId, DispatchOrchestrator $orchestrator)
    {
        $offer = MkDispatchOffer::where('driver_id', $this->driver()->id)->findOrFail($offerId);

        if (! $orchestrator->accept($offer)) {
            return response()->json(['error' => 'Offer is no longer available.'], 409);
        }

        return response()->json($this->orderDetail(Order::findOrFail($offer->order_id)));
    }

    /** POST /driver/offers/{id}/reject — reject; the orchestrator cascades. */
    public function rejectOffer(int $offerId, DispatchOrchestrator $orchestrator)
    {
        $offer = MkDispatchOffer::where('driver_id', $this->driver()->id)->findOrFail($offerId);

        $orchestrator->reject($offer);

        return response()->json(['ok' => true]);
    }

    /** POST /driver/shift — online | offline | break (FleetRegistry owns the transition). */
    public function shift(Request $request, FleetRegistry $registry)
    {
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', FleetRegistry::SHIFT_STATUSES),
        ]);

        $driver = $registry->setShift($this->driver(), $data['status']);

        return response()->json(['ok' => true, 'shift_status' => $driver->shift_status]);
    }

    /**
     * GET /driver/earnings — honest v1: per-day delivered count + order-total
     * sum for the last 30 days. Order totals are COMPUTED (Order::getTotalAttribute
     * from line items — there is no orders.total column), so items are eager-
     * loaded and grouping happens in PHP (also prefix-safe: no raw SQL).
     */
    public function earnings()
    {
        $orders = Order::where('driver_id', $this->driver()->id)
            ->where('delivery_status', 'delivered')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', now()->subDays(30)->startOfDay())
            ->orderByDesc('delivered_at')
            ->with('items')
            ->get();

        // MC-43: a driver may deliver for stores in different currencies, so money
        // is never blind-summed across them. Each order's stamped currency (null → its
        // store's currency) keys the honest split; the flat total/tips stay for a
        // single-currency driver (byte-identical: one bucket == the flat sum).
        $base = platform_currency();
        // A legacy order without a stamp is in its own store's currency (else the platform's).
        $cur = fn (Order $o) => strtoupper((string) ($o->currency_code ?: ($o->store_id ? store_currency((int) $o->store_id) : $base)));
        // P1-05: a sum in ONE known currency rounds to that currency's decimals (the rule order totals use), HALF_UP
        // and PHP-version independent; a mixed-currency sum keeps the old 2 (it has no currency).
        $sum = fn ($group, string $field, ?string $code) => Money::round(
            $group->sum(fn ($o) => (float) $o->{$field}),
            $code !== null ? currency_decimals($code) : 2
        );

        // delivered_at has no datetime cast on Order — parse defensively.
        $days = $orders->groupBy(fn (Order $o) => Carbon::parse($o->delivered_at)->toDateString())
            ->map(function ($group, $date) use ($cur, $sum) {
                $codes = $group->map($cur)->unique();
                $code = $codes->count() === 1 ? $codes->first() : null;

                return [
                    'date' => $date,
                    'deliveries' => $group->count(),
                    'total' => $sum($group, 'total', $code),
                    'tips' => $sum($group, 'tip_amount', $code),
                    // The day's currency; null when a single day mixes currencies.
                    'currency' => $codes->count() === 1 ? $codes->first() : null,
                ];
            })->values();

        // Per-currency earnings map (highest total first) — the honest breakdown.
        $byCurrency = $orders->groupBy($cur)
            ->map(fn ($group, $code) => [
                'currency' => $code,
                'deliveries' => $group->count(),
                'total' => $sum($group, 'total', (string) $code),
                'tips' => $sum($group, 'tip_amount', (string) $code),
            ])
            ->sortByDesc('total')->values();

        $single = $byCurrency->count() === 1 ? (string) $byCurrency->first()['currency'] : null;

        return response()->json([
            'data' => $days,
            'summary' => [
                'deliveries' => $orders->count(),
                'total' => $sum($orders, 'total', $single),
                'tips' => $sum($orders, 'tip_amount', $single),
                // Dominant/base currency for a single-currency driver; the map below
                // carries the per-currency truth when they span more than one.
                'currency' => $byCurrency->count() === 1 ? $byCurrency->first()['currency'] : $base,
                'by_currency' => $byCurrency,
            ],
        ]);
    }

    /**
     * P6-237: POST /driver/logout — tells the browser to drop every cached
     * response and stored value for this origin (customer names, addresses,
     * phones, the token). The PWA also messages its service worker to purge.
     */
    public function logout(): JsonResponse
    {
        return response()->json(['ok' => true])
            ->header('Clear-Site-Data', '"cache", "storage"')
            ->header('Cache-Control', 'no-store');
    }

    /** PUT /driver/location — live driver position for tracking. */
    public function updateLocation(Request $request)
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        $this->driver()->update([
            'last_lat' => $data['lat'],
            'last_lng' => $data['lng'],
            'last_location_at' => now(),
        ]);

        return response()->json(['ok' => true, 'updated_at' => now()->toIso8601String()]);
    }

    /** HPMS E11: register this driver device for native push (Bearer clients). */
    public function registerDevice(Request $request): JsonResponse
    {
        $d = $this->driver();
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);
        try {
            $sub = app(MobilePush::class)->registerDevice(
                (int) $d->store_id, $data['token'], $data['platform'] ?? 'fcm', ['driver_id' => $d->id], null, null
            );
        } catch (ValidationException $e) {
            return response()->json(['error' => 'feature_disabled', 'message' => $e->getMessage()], 403);
        }

        return response()->json(['ok' => true, 'id' => $sub->id]);
    }

    /** HPMS E11: unregister a driver device by its push token. */
    public function unregisterDevice(Request $request): JsonResponse
    {
        $d = $this->driver();
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        $removed = PushSubscription::where('store_id', $d->store_id)
            ->where('endpoint_hash', hash('sha256', $data['token']))->delete();

        return response()->json(['ok' => true, 'removed' => (bool) $removed]);
    }
}
