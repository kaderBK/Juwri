<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Marketplace;

use App\Models\Driver;
use App\Models\MkDispatchOffer;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Modules\Delivery\Services\Couriers\DispatchService as CourierDispatchService;
use App\Services\DeliveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Marketplace T4-2: dispatch orchestrator.
 *
 * On order ready-for-delivery, resolves the store's delivery mode and drives
 * the offer → accept flow for platform fleet drivers. Assignment writes the
 * SAME fields the self-driver path uses (orders.driver_id + delivery_status
 * via DeliveryService::setStatus) so tracking, the driver app and webhooks
 * behave identically for fleet and store drivers.
 *
 * Mode resolution (resolveMode) — honest rules:
 *  - mk_delivery_modes empty/unset = legacy store → 'self' (today's own-fleet
 *    behavior: owner assigns manually or via driver self-claim).
 *  - 'self' enabled AND the store has at least one active own driver → 'self'.
 *  - else 'fleet' enabled → 'fleet' (platform drivers, offer cascade below).
 *  - else 'courier' enabled → 'courier' (3rd-party handoff).
 *  - else (modes configured but none usable, e.g. only ['self'] with no
 *    active drivers) → 'courier' as the last-resort fallback, documented here
 *    because a marketplace order must go SOMEWHERE.
 *
 * Fleet flow: offer the order to the top-N (config marketplace.dispatch.
 * offer_batch) nearest available fleet drivers around the store's mk_lat/mk_lng,
 * scoped to the store's mk_city_id (tenant safety: a driver from another city
 * is never offered). Each offer expires after offer_ttl_seconds; reject/expiry
 * cascades to the next-nearest driver not yet offered. When the fleet is
 * exhausted the order falls back to the courier path; if no 3rd-party courier
 * is connected either, the order is left unassigned (delivery_status stays
 * 'pending') and a warning is logged for the ops dashboard (T5-5).
 *
 * active_load bookkeeping is owned HERE (T4-1 contract): accept() increments,
 * complete() decrements. complete() is invoked from the DeliveryService::
 * setStatus terminal-transition seam so every delivered/failed path (driver
 * app, owner API, admin) releases fleet capacity exactly once.
 */
class DispatchOrchestrator
{
    /* ----------------------------------------------------------------- mode */

    /**
     * Resolve the delivery mode for a store: 'self' | 'fleet' | 'courier'.
     * See class docblock for the honest rules.
     */
    public function resolveMode(Store $store): string
    {
        $modes = array_values(array_intersect(
            (array) ($store->mk_delivery_modes ?? []),
            ['self', 'courier', 'fleet']
        ));

        if ($modes === []) {
            return 'self'; // legacy store: keep today's own-fleet behavior
        }

        if (in_array('self', $modes, true) && $this->hasActiveStoreDrivers($store)) {
            return 'self';
        }
        if (in_array('fleet', $modes, true)) {
            return 'fleet';
        }
        if (in_array('courier', $modes, true)) {
            return 'courier';
        }

        // Modes configured but none usable (e.g. only ['self'] with no active
        // drivers): courier is the last-resort fallback.
        return 'courier';
    }

    private function hasActiveStoreDrivers(Store $store): bool
    {
        return Driver::where('store_id', $store->id)
            ->where('is_fleet', false)
            ->where('active', true)
            ->exists();
    }

    /* ------------------------------------------------------------- dispatch */

    /**
     * Entry point on order ready-for-delivery. Returns the outcome:
     * 'self' | 'offered' | 'courier' | 'unassigned' | 'assigned' (already has
     * a driver — idempotent no-op).
     */
    public function dispatch(Order $order): string
    {
        $this->expireDueOffers(); // lazy sweep — no cron dependency

        if ($order->driver_id) {
            return 'assigned';
        }

        $store = Store::find($order->store_id);
        if (!$store) {
            Log::warning('mk-dispatch: order without store', ['order_id' => $order->id]);
            return 'unassigned';
        }

        return match ($this->resolveMode($store)) {
            'self' => $this->dispatchSelf($order),
            'fleet' => $this->offerNext($order, $store),
            default => $this->courierFallback($order),
        };
    }

    /**
     * Self mode: put the order in the store's own deliverable pool (same as
     * OwnFleetProvider::dispatch) — assignment stays the owner's existing
     * manual/self-claim step.
     */
    private function dispatchSelf(Order $order): string
    {
        if (empty($order->delivery_status) || $order->delivery_status === 'pending') {
            DeliveryService::setStatus($order, 'pending');
        }

        return 'self';
    }

    /**
     * Offer the order to the next batch of nearest available fleet drivers
     * that have not been offered this order before. Exhausted → courier.
     */
    private function offerNext(Order $order, Store $store): string
    {
        if ($store->mk_lat === null || $store->mk_lng === null) {
            Log::warning('mk-dispatch: store has no mk_lat/mk_lng, skipping fleet', [
                'order_id' => $order->id, 'store_id' => $store->id,
            ]);
            return $this->courierFallback($order);
        }

        $batch = max(1, self::settingInt('mk_dispatch_offer_batch', 'offer_batch', 1));
        // A driver is "burned" for this order only by a live offer or an
        // explicit reject/expiry. Cancelled (sibling-cancelled on accept, or
        // T4-5 manual reassign/unassign) and accepted-then-unassigned offers
        // must NOT burn the driver, or the admin re-dispatch button could
        // never re-offer the fleet after putting an order back in the pool.
        $alreadyOffered = MkDispatchOffer::where('order_id', $order->id)
            ->whereIn('status', ['offered', 'rejected', 'expired'])
            ->pluck('driver_id')->all();

        $candidates = new FleetRegistry()
            ->nearestAvailable(
                (float) $store->mk_lat,
                (float) $store->mk_lng,
                $store->mk_city_id ? (int) $store->mk_city_id : null,
                $batch + count($alreadyOffered)
            )
            ->reject(fn (Driver $d) => in_array($d->id, $alreadyOffered, true))
            ->take($batch)
            ->values();

        if ($candidates->isEmpty()) {
            return $this->courierFallback($order);
        }

        $ttl = max(1, self::settingInt('mk_dispatch_offer_ttl', 'offer_ttl_seconds', 60));
        foreach ($candidates as $driver) {
            MkDispatchOffer::create([
                'order_id' => $order->id,
                'driver_id' => $driver->id,
                'status' => 'offered',
                'expires_at' => now()->addSeconds($ttl),
            ]);
        }

        return 'offered';
    }

    /* -------------------------------------------------------- accept/reject */

    /**
     * Driver accepts an offer. Assigns exactly like the self-driver path
     * (DeliveryService::setStatus 'assigned' → driver_id, assigned_at,
     * order.driver_assigned webhook), increments the driver's active_load and
     * cancels sibling pending offers. Returns false when the offer is no
     * longer acceptable (expired / already handled / order taken) — an
     * expired-on-arrival accept also triggers the cascade.
     */
    public function accept(MkDispatchOffer $offer): bool
    {
        if ($offer->isPending() && $offer->isExpiredByClock()) {
            $this->expireDueOffers();
            return false;
        }

        return DB::transaction(function () use ($offer) {
            $fresh = MkDispatchOffer::lockForUpdate()->find($offer->id);
            if (!$fresh || !$fresh->isPending() || $fresh->isExpiredByClock()) {
                return false;
            }

            $order = Order::lockForUpdate()->find($fresh->order_id);
            $driver = Driver::lockForUpdate()->find($fresh->driver_id);
            if (!$order || !$driver || $order->driver_id || !$driver->isAvailable()) {
                $fresh->update(['status' => 'cancelled', 'responded_at' => now()]);
                return false;
            }

            $fresh->update(['status' => 'accepted', 'responded_at' => now()]);

            // SAME write path as store self-drivers: tracking Just Works.
            DeliveryService::setStatus($order, 'assigned', $driver->id);

            $driver->increment('active_load');

            MkDispatchOffer::where('order_id', $order->id)
                ->where('id', '!=', $fresh->id)
                ->pending()
                ->update(['status' => 'cancelled', 'responded_at' => now()]);

            $offer->refresh();

            return true;
        });
    }

    /**
     * Driver rejects an offer → cascade to the next-nearest driver.
     */
    public function reject(MkDispatchOffer $offer): void
    {
        if (!$offer->isPending()) {
            return;
        }

        $offer->update(['status' => 'rejected', 'responded_at' => now()]);
        $this->cascade($offer);
    }

    /* ----------------------------------------------------------- expiry */

    /**
     * Sweep offers past their TTL and cascade each affected order to the next
     * driver. Safe to call from the scheduler; also invoked lazily inside
     * dispatch()/accept() so no cron dependency exists. Returns swept count.
     */
    public function expireDueOffers(): int
    {
        $due = MkDispatchOffer::pending()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($due as $offer) {
            $offer->update(['status' => 'expired', 'responded_at' => now()]);
            $this->cascade($offer);
        }

        // T4-4: after the cascades settle, escalate timed-out orders to a
        // courier. Runs here (and therefore inside every dispatch() call,
        // which sweeps lazily) plus from the scheduler — no cron dependency.
        $this->fallbackDueOrders();

        return $due->count();
    }

    /**
     * T4-4: courier fallback for timed-out fleet dispatches. Finds delivery
     * orders whose offer history is entirely dead (no pending offer left) and
     * whose FIRST offer is older than marketplace.dispatch.courier_fallback_
     * minutes, and runs the courier fallback for each — the exact path the
     * fleet-exhausted case uses.
     *
     * Guards (all honest, all tested):
     *  - config courier_fallback_enabled=false disables the sweep entirely;
     *  - store allow flag: the store's mk_delivery_modes must include
     *    'courier', else the order stays unassigned and a warning is logged
     *    (city-level allow is a T5-6 kill-switch seam — store-level only here);
     *  - idempotent: an order that already has a CourierDelivery row (from the
     *    exhaustion path or an earlier sweep) is never double-escalated;
     *  - an order that got a driver (fleet accept or manual assign) is skipped.
     *
     * Returns the number of orders escalated to a courier.
     */
    public function fallbackDueOrders(): int
    {
        if (!config('marketplace.dispatch.courier_fallback_enabled', true)) {
            return 0;
        }

        $minutes = max(1, self::settingInt('mk_dispatch_courier_fallback_min', 'courier_fallback_minutes', 5));
        $cutoff = now()->subMinutes($minutes);

        // Column-only aggregates: no table names in raw SQL (prefix-safe).
        $orderIds = MkDispatchOffer::query()
            ->select('order_id')
            ->groupBy('order_id')
            ->havingRaw('MIN(created_at) <= ?', [$cutoff])
            ->havingRaw("SUM(CASE WHEN status = 'offered' THEN 1 ELSE 0 END) = 0")
            ->pluck('order_id');

        $escalated = 0;
        foreach ($orderIds as $orderId) {
            $order = Order::find($orderId);
            if (!$order || $order->driver_id) {
                continue; // gone, or a driver took it after all
            }
            if (\Juwri\Modules\Delivery\Models\CourierDelivery::where('order_id', $order->id)->exists()) {
                continue; // already escalated — never double-create
            }

            $store = Store::find($order->store_id);
            if (!$store || !in_array('courier', (array) ($store->mk_delivery_modes ?? []), true)) {
                Log::warning('mk-dispatch: courier fallback due but store does not allow courier', [
                    'order_id' => $order->id, 'store_id' => $store?->id,
                ]);
                continue;
            }

            if ($this->courierFallback($order) === 'courier') {
                $escalated++;
            }
        }

        return $escalated;
    }

    /** Re-offer an order after a reject/expiry, unless it got a driver meanwhile. */
    private function cascade(MkDispatchOffer $offer): void
    {
        $order = Order::find($offer->order_id);
        if (!$order || $order->driver_id) {
            return;
        }
        if (MkDispatchOffer::where('order_id', $order->id)->pending()->exists()) {
            return; // another live offer is still out
        }

        $store = Store::find($order->store_id);
        if ($store) {
            $this->offerNext($order, $store);
        }
    }

    /* ----------------------------------------------------- manual ops (T4-5) */

    /**
     * L4 T4-5: manual reassign from the superadmin dispatch board.
     *
     * Documented public reuse of the accept() internals: assignment goes
     * through the SAME write path (DeliveryService::setStatus 'assigned' →
     * driver_id + assigned_at + order.driver_assigned webhook) and the SAME
     * active_load bookkeeping (old fleet driver decremented, new fleet driver
     * incremented — store drivers untouched, load is fleet-only). Pending
     * offers for the order are cancelled so no driver can accept a stale one.
     *
     * Guards:
     *  - terminal orders (delivered/failed) are never reassigned → error;
     *  - inactive drivers → error;
     *  - a store (non-fleet) driver of ANOTHER store → error (cross-tenant);
     *  - overloaded / off-shift / other-city fleet driver → WARN, not block
     *    (the admin is the human override — the board surfaces the warning).
     *
     * @return array{ok:bool,error?:string,warnings?:string[]}
     */
    public function reassign(Order $order, Driver $driver): array
    {
        return DB::transaction(function () use ($order, $driver) {
            $order = Order::lockForUpdate()->find($order->id);
            $driver = Driver::lockForUpdate()->find($driver->id);
            if (!$order || !$driver) {
                return ['ok' => false, 'error' => 'not_found'];
            }
            if (in_array((string) $order->delivery_status, ['delivered', 'failed'], true)) {
                return ['ok' => false, 'error' => 'terminal'];
            }
            if (!$driver->active) {
                return ['ok' => false, 'error' => 'driver_inactive'];
            }
            // A null store_id is an owner-wide (non-fleet) driver — assignable to
            // any of that owner's stores, exactly what the assignDriver controller admits
            // via `whereNull OR store_id = store`. Only a driver BOUND to a DIFFERENT
            // store is the cross-tenant case this guard exists to block.
            if (!$driver->is_fleet && $driver->store_id !== null && (int) $driver->store_id !== (int) $order->store_id) {
                return ['ok' => false, 'error' => 'driver_other_store'];
            }
            if ((int) $order->driver_id === (int) $driver->id) {
                return ['ok' => true, 'warnings' => ['already_assigned']];
            }

            $warnings = [];
            if ($driver->is_fleet) {
                if ($driver->shift_status !== 'online') {
                    $warnings[] = 'driver_offline';
                }
                if ((int) $driver->active_load >= (int) $driver->capacity) {
                    $warnings[] = 'driver_at_capacity';
                }
                $storeCity = Store::where('id', $order->store_id)->value('mk_city_id');
                if ($storeCity && $driver->city_id && (int) $driver->city_id !== (int) $storeCity) {
                    $warnings[] = 'driver_other_city';
                }
            }

            // Release the old fleet driver's capacity (same guard complete() uses).
            if ($order->driver_id) {
                $old = Driver::lockForUpdate()->find($order->driver_id);
                if ($old && $old->is_fleet) {
                    Driver::where('id', $old->id)
                        ->where('active_load', '>', 0)
                        ->decrement('active_load');
                }
            }

            // No driver may accept a stale offer after the manual override.
            MkDispatchOffer::where('order_id', $order->id)->pending()
                ->update(['status' => 'cancelled', 'responded_at' => now()]);

            // SAME write path as accept(): tracking + driver app Just Work.
            DeliveryService::setStatus($order, 'assigned', $driver->id);

            if ($driver->is_fleet) {
                $driver->increment('active_load');
            }

            return ['ok' => true, 'warnings' => $warnings];
        });
    }

    /**
     * L4 T4-5: manual unassign — puts the order back in the pool (driver_id
     * cleared, delivery_status back to 'pending', pending offers cancelled,
     * old fleet driver's active_load released). The admin can then hit the
     * re-dispatch button which calls dispatch() for a fresh offer cascade.
     */
    public function unassign(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $order = Order::lockForUpdate()->find($order->id);
            if (!$order || in_array((string) $order->delivery_status, ['delivered', 'failed'], true)) {
                return false;
            }

            if ($order->driver_id) {
                $old = Driver::lockForUpdate()->find($order->driver_id);
                if ($old && $old->is_fleet) {
                    Driver::where('id', $old->id)
                        ->where('active_load', '>', 0)
                        ->decrement('active_load');
                }
                $order->driver_id = null;
                $order->save();
            }

            MkDispatchOffer::where('order_id', $order->id)->pending()
                ->update(['status' => 'cancelled', 'responded_at' => now()]);

            DeliveryService::setStatus($order, 'pending');

            return true;
        });
    }

    /* --------------------------------------------------------- completion */

    /**
     * Release fleet capacity when a delivery reaches a terminal status.
     * Wired from DeliveryService::setStatus (the single choke point for
     * delivered/failed transitions — driver app, owner API and admin all go
     * through it); the caller guarantees the transition is non-terminal →
     * terminal, so the decrement happens exactly once per delivery.
     * Store (non-fleet) drivers are untouched — active_load is fleet-only.
     */
    public function complete(Order $order): void
    {
        if (!$order->driver_id) {
            return;
        }

        $driver = Driver::find($order->driver_id);
        if (!$driver || !$driver->is_fleet) {
            return;
        }

        Driver::where('id', $driver->id)
            ->where('active_load', '>', 0)
            ->decrement('active_load');
    }

    /* --------------------------------------------------------- courier */

    /**
     * Courier fallback — HONEST SEAM: there is no live 3rd-party courier
     * account wired in dev. We reuse the existing courier scaffold
     * (Couriers\DispatchService::escalateToThirdParty → cheapest connected
     * provider, Uber Direct / DoorDash). When no provider is connected it
     * returns null and the order is left unassigned ('pending') with a
     * warning log — surfaced by the superadmin dispatch board (T5-5).
     */
    private function courierFallback(Order $order): string
    {
        // T5-5: per-city courier kill switch (setting mk_dispatch_courier_
        // cities_off, JSON list of city ids). When the order's store city is
        // toggled off, the fallback is skipped with a warning — the order
        // stays in the pool for manual ops instead of being handed to a
        // 3rd-party courier.
        $cityId = (int) Store::where('id', $order->store_id)->value('mk_city_id');
        if ($cityId && in_array($cityId, self::courierCitiesOff(), true)) {
            if (empty($order->delivery_status)) {
                DeliveryService::setStatus($order, 'pending');
            }
            Log::warning('mk-dispatch: courier fallback disabled for city, order unassigned', [
                'order_id' => $order->id, 'city_id' => $cityId,
            ]);

            return 'unassigned';
        }

        try {
            $courierDelivery = CourierDispatchService::escalateToThirdParty($order);
        } catch (\Throwable $e) {
            Log::warning('mk-dispatch: courier fallback failed', [
                'order_id' => $order->id, 'error' => $e->getMessage(),
            ]);
            $courierDelivery = null;
        }

        if ($courierDelivery) {
            return 'courier';
        }

        if (empty($order->delivery_status)) {
            DeliveryService::setStatus($order, 'pending');
        }
        Log::warning('mk-dispatch: no fleet driver or courier available, order unassigned', [
            'order_id' => $order->id,
        ]);

        return 'unassigned';
    }

    /* --------------------------------------------------- rule settings (T5-5) */

    /**
     * T5-5: dispatch rules are admin-editable. A persisted Option (loaded
     * into config('settings.*') by SettingsServiceProvider, patched live by
     * FusionDeliveryController::saveRules) overrides the env-backed
     * config('marketplace.dispatch.*') default. Empty string / null = no
     * override — guarded so a cleared field falls back to config.
     */
    public static function settingInt(string $settingKey, string $configKey, int $default): int
    {
        $value = config('settings.' . $settingKey);
        if ($value === null || $value === '') {
            $value = config('marketplace.dispatch.' . $configKey, $default);
        }

        return (int) $value;
    }

    /** T5-5: city ids where the courier fallback is switched off (JSON list setting). */
    public static function courierCitiesOff(): array
    {
        $value = config('settings.mk_dispatch_courier_cities_off');
        if (is_string($value)) {
            $value = json_decode($value, true) ?: [];
        }

        return array_values(array_map('intval', (array) ($value ?? [])));
    }
}
