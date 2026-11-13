<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use App\Models\Order;
use App\Services\FeatureAccess;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\BranchHourException;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Contracts\MobilePush;
use Juwri\Modules\Ordering\Contracts\HeldSlotCounts;
use Juwri\Modules\Ordering\Services\CustomerPushService;

/**
 * T1 — Scheduled orders / pre-order.
 *
 * Generates bookable time slots for a restaurant/branch/order-type and validates a
 * customer-requested time against the restaurant's rules (enabled, lead time, horizon,
 * opening hours, per-slot capacity). Pure/read-only except capacity counting; the two
 * order-writers (OrderApiService::create, StoreController::submitOrder) call validate()
 * and computeFireAt() — they never re-derive these rules.
 *
 * Settings are per-restaurant StoreOption key/values (see keys() below). All default so
 * that a restaurant which never configured scheduling behaves exactly as before.
 */
class SchedulingService
{
    /** Default slot interval (minutes) when unset. */
    public const DEFAULT_INTERVAL = 15;

    /** Default minimum lead time (minutes) before the earliest bookable slot. */
    public const DEFAULT_LEAD = 30;

    /** Default horizon (days ahead) a customer may schedule. */
    public const DEFAULT_MAX_DAYS = 7;

    /** Default kitchen prep minutes (scheduled_at - prep = fire_at). */
    public const DEFAULT_PREP = 20;

    /**
     * Order types this applies to. `orders.type` is a free-text VARCHAR now
     * (F0-3), sourced from App\Services\Verticals\FulfillmentRegistry.
     *
     * rev/multi-vertical B3 added 'pickup_slot'/'appointment' so a non-food
     * store can reuse this exact slot picker/lock machinery for its own
     * checkout (SlotService is a DIFFERENT, marketplace-only picker this
     * lane deliberately does not touch). Purely additive: a legacy store
     * never sets `scheduling_types` to either value, `enabledTypes()`
     * defaults to (and is capped by) this list, and neither new value maps
     * to a food order path — a restaurant can never actually create one.
     * P3-00 adds 'at_home' (a services store that visits the customer) the same way.
     */
    public const TYPES = ['on-table', 'takeaway', 'delivery', 'room_service', 'pickup_slot', 'appointment', 'at_home'];

    /** Owner-settable StoreOption keys this service reads (whitelisted in StoreController). */
    public static function keys(): array
    {
        return [
            'allow_order_scheduling',          // master toggle (bool-ish)
            'scheduling_types',                // csv subset of TYPES, empty = all enabled types
            'scheduling_lead_minutes',
            'scheduling_slot_interval',
            'scheduling_max_days',
            'scheduling_prep_minutes',
            'scheduling_slot_capacity',        // max orders per slot, 0/empty = unlimited
        ];
    }

    /** Is scheduling enabled at all for this restaurant (optionally for a given type)? */
    public function enabled(int $storeId, ?string $orderType = null): bool
    {
        /* Platform kill-switch (a global OFF disables pre-ordering everywhere;
           absent config = enabled) AND — FIX-2 F2-2 (gap audit) — the store
           OWNER's entitlement once `scheduled_orders` is plan_gated. The
           per-store toggle alone was the whole gate, so the $0.99/mo SKU bought
           nothing. Store-scoped on purpose: the customer placing the order is a
           guest, never the subject of the entitlement. */
        if (! $this->storeEntitled($storeId)) {
            return false;
        }
        if (empty(store_options($storeId, 'allow_order_scheduling'))) {
            return false;
        }
        if ($orderType === null) {
            return true;
        }
        $types = $this->enabledTypes($storeId);

        return in_array($orderType, $types, true);
    }

    /**
     * Kill veto + (once plan_gated) the store OWNER's entitlement. Shared
     * serve-time primitive — see App\Services\FeatureAccess for the subject
     * rationale and the two-lane contract.
     */
    public function storeEntitled(int $storeId): bool
    {
        return FeatureAccess::storeAllows($storeId, 'scheduled_orders');
    }

    /** Which order types allow scheduling. Empty setting = all valid types. */
    public function enabledTypes(int $storeId): array
    {
        $raw = trim((string) store_options($storeId, 'scheduling_types', ''));
        if ($raw === '') {
            return self::TYPES;
        }
        $types = array_values(array_intersect(
            array_map('trim', explode(',', $raw)),
            self::TYPES
        ));

        return $types ?: self::TYPES;
    }

    public function leadMinutes(int $storeId): int
    {
        return max(0, (int) store_options($storeId, 'scheduling_lead_minutes', self::DEFAULT_LEAD));
    }

    public function slotInterval(int $storeId): int
    {
        $v = (int) store_options($storeId, 'scheduling_slot_interval', self::DEFAULT_INTERVAL);

        return $v > 0 ? $v : self::DEFAULT_INTERVAL;
    }

    public function maxDays(int $storeId): int
    {
        $v = (int) store_options($storeId, 'scheduling_max_days', self::DEFAULT_MAX_DAYS);

        return $v > 0 ? $v : self::DEFAULT_MAX_DAYS;
    }

    public function prepMinutes(int $storeId): int
    {
        return max(0, (int) store_options($storeId, 'scheduling_prep_minutes', self::DEFAULT_PREP));
    }

    /** Per-slot capacity; 0 = unlimited. Typed read (Phase 3 F2: an int setting; a pre-fix stored `true` reads as 1). */
    public function slotCapacity(int $storeId): int
    {
        return max(0, StoreSettings::int($storeId, 'scheduling_slot_capacity', 0));
    }

    /**
     * Is the slot that contains $at already at/over capacity RIGHT NOW? A read-only
     * re-check used to make the capacity guard ATOMIC: validate()'s count and the order
     * INSERT are otherwise separated by a race window, so the caller re-checks this while
     * holding a per-slot lock. Unlimited capacity (0) is never full. Buckets the same way
     * slotCounts()/slotsFor() do (store tz, floored to the interval).
     */
    public function slotIsFull(int $storeId, ?Branch $branch, Carbon $at): bool
    {
        $capacity = $this->slotCapacity($storeId);
        if ($capacity <= 0) {
            return false;
        }
        $interval = $this->slotInterval($storeId);
        $tz = $this->timezone($storeId, $branch);
        $local = $at->copy()->setTimezone($tz);
        $mins = (int) floor(($local->hour * 60 + $local->minute) / $interval) * $interval;
        $slotStart = $local->copy()->startOfDay()->addMinutes($mins);

        $counts = $this->slotCounts($storeId, $branch, $slotStart, $slotStart->copy()->addMinutes($interval), $interval);

        return (int) ($counts[$slotStart->format('Y-m-d H:i')] ?? 0) >= $capacity;
    }

    /**
     * Acquire and return the per-slot capacity lock for a scheduled order, re-checking
     * occupancy while it is held. The caller MUST hold the returned lock across the order
     * INSERT and its COMMIT, then release it (see OrderApiService::create's finally and
     * StoreController::submitOrder). Returns null when no lock is needed — an ASAP order
     * (no $slot) or an unlimited-capacity store — so the caller can `optional($lock)->release()`
     * unconditionally.
     *
     * Closes the storefront check-then-insert race: validate() counts the slot, but the
     * row lands later (the legacy transaction, or OrderApiService::create), so two
     * concurrent bookers could both pass the non-atomic count and overfill a
     * capacity-limited pre-order window. Serialising on this key + a re-check under it is
     * the SAME guard OrderApiService already applied to its own path; both branches use
     * this method so the key and the check can never drift apart.
     *
     * Throws InvalidArgumentException when the slot is momentarily busy (lock timeout) or
     * already full — the caller renders that as the storefront "pick another slot" message.
     */
    public function lockSlot(int $storeId, ?Branch $branch, ?Carbon $slot): ?Lock
    {
        if (! $slot || $this->slotCapacity($storeId) <= 0) {
            return null; // ASAP, or unlimited capacity → nothing to serialise
        }

        $lock = Cache::lock(self::lockKey($storeId, $branch, $slot), 15);
        try {
            $lock->block(5);
        } catch (LockTimeoutException $e) {
            throw new \InvalidArgumentException(__('That time slot is busy. Please try again.'));
        }
        if ($this->slotIsFull($storeId, $branch, $slot)) {
            $lock->release();
            throw new \InvalidArgumentException(__('That time slot is full. Please pick another.'));
        }

        return $lock;
    }

    /**
     * Lane C (P3-06b food schedule-ahead holds): the per-slot lock key, shared by the order paths (lockSlot) and the
     * headless API's 10-minute slot holds (SchedulingSlots), so a hold's seat and an order's count of the same slot are
     * never taken at the same time. `$slot` is the slot start in the store's time.
     */
    public static function lockKey(int $storeId, ?Branch $branch, CarbonInterface $slot): string
    {
        return 'sched-slot:'.$storeId.':'.(optional($branch)->id ?: 0).':'.$slot->format('YmdHi');
    }

    /**
     * Lane C: occupancy per slot key ('Y-m-d H:i', store time) within [start, end): scheduled orders that are not
     * cancelled + the live seats of the headless API's schedule-ahead holds not yet taken by an order (a hold's seat
     * that an order took counts once, as the order).
     *
     * @return array<string, int>
     */
    public function slotUsage(int $storeId, ?Branch $branch, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->slotCounts($storeId, $branch, $start, $end, $this->slotInterval($storeId));
    }

    /** Timezone for a restaurant/branch (branch wins, else restaurant option, else app). */
    public function timezone(int $storeId, ?Branch $branch = null): string
    {
        if ($branch && ! empty($branch->timezone)) {
            return $branch->timezone;
        }

        return (string) (store_options($storeId, 'timezone') ?: config('app.timezone', 'UTC'));
    }

    /**
     * Bookable slots for a given local date (Y-m-d). Returns an ordered list of
     * ['at' => Carbon (tz-aware), 'label' => 'H:i', 'full' => bool].
     * Respects branch hours (incl. overnight + closed days), lead time, horizon,
     * and per-slot capacity. Returns [] when scheduling is off or the date is out of range.
     */
    public function slotsFor(int $storeId, ?Branch $branch, string $orderType, string $date): array
    {
        if (! $this->enabled($storeId, $orderType)) {
            return [];
        }

        $tz = $this->timezone($storeId, $branch);
        try {
            $day = Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
        } catch (\Throwable $e) {
            return [];
        }
        $now = Carbon::now($tz);

        // Horizon + past-day guard.
        if ($day->lt($now->copy()->startOfDay()) || $day->gt($now->copy()->addDays($this->maxDays($storeId))->startOfDay())) {
            return [];
        }

        [$open, $close, $closed] = $this->windowFor($branch, $day);
        if ($closed) {
            return [];
        }

        $interval = $this->slotInterval($storeId);
        $earliest = $now->copy()->addMinutes($this->leadMinutes($storeId));

        // Build [start, end) for the day. Overnight windows (close < open) roll to next day.
        $start = $day->copy()->setTimeFromTimeString($open);
        $end = $day->copy()->setTimeFromTimeString($close);
        if ($end->lte($start)) {
            $end->addDay();
        }

        $capacity = $this->slotCapacity($storeId);
        $counts = $capacity > 0 ? $this->slotCounts($storeId, $branch, $start, $end, $interval) : [];

        $slots = [];
        for ($t = $start->copy(); $t->lt($end); $t->addMinutes($interval)) {
            if ($t->lt($earliest)) {
                continue; // inside lead-time window
            }
            $key = $t->format('Y-m-d H:i');
            $used = $counts[$key] ?? 0;
            $slots[] = [
                'at' => $t->copy(),
                'label' => $t->format('H:i'),
                'full' => $capacity > 0 && $used >= $capacity,
            ];
        }

        return $slots;
    }

    /**
     * Validate a requested schedule time. Returns a normalized Carbon (in the restaurant
     * tz) on success, or throws \InvalidArgumentException with a customer-safe message.
     * ASAP orders (null/empty $requested) return null and are always valid.
     */
    public function validate(int $storeId, ?Branch $branch, string $orderType, ?string $requested): ?Carbon
    {
        if ($requested === null || trim($requested) === '') {
            return null; // ASAP
        }
        if (! $this->enabled($storeId, $orderType)) {
            throw new \InvalidArgumentException(__('Scheduled ordering is not available.'));
        }

        $tz = $this->timezone($storeId, $branch);
        try {
            $at = Carbon::parse($requested, $tz);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(__('That scheduled time is invalid.'));
        }

        $now = Carbon::now($tz);
        if ($at->lt($now->copy()->addMinutes($this->leadMinutes($storeId))->subSeconds(1))) {
            throw new \InvalidArgumentException(__('Please pick a later time.'));
        }
        // Horizon is DAY-granular, matching slotsFor()'s picker guard (line ~222): the
        // whole calendar day `today + maxDays` is bookable. A to-the-second cutoff here
        // (at > now + maxDays) rejected any slot on that last day later than the current
        // wall-clock time — slots the picker had shown — so a diner picking, say, an 8pm
        // slot on the horizon day got "too far ahead". Compare start-of-day to start-of-day
        // so what the picker offers is exactly what validate() accepts.
        if ($at->copy()->startOfDay()->gt($now->copy()->addDays($this->maxDays($storeId))->startOfDay())) {
            throw new \InvalidArgumentException(__('That time is too far ahead.'));
        }

        // Must fall on/inside an open window for its day and align with a real slot.
        $daySlots = $this->slotsFor($storeId, $branch, $orderType, $at->format('Y-m-d'));
        // Overnight slots can spill to the next calendar day; also check the previous day.
        if (empty($this->matchSlot($daySlots, $at))) {
            $prev = $this->slotsFor($storeId, $branch, $orderType, $at->copy()->subDay()->format('Y-m-d'));
            $match = $this->matchSlot($prev, $at);
        } else {
            $match = $this->matchSlot($daySlots, $at);
        }
        // P6-291: a slot's OWN calendar date being a closed branch_hour_exception
        // refuses it — even when an overnight spillover from yesterday's window
        // generated the candidate. Keep-it-simple: refuse on the spill date.
        if (! empty($match) && $this->hasClosedException($branch, $at->format('Y-m-d'))) {
            throw new \InvalidArgumentException(__('The restaurant is closed at that time.'));
        }
        if (empty($match)) {
            throw new \InvalidArgumentException(__('The restaurant is closed at that time.'));
        }
        if (! empty($match['full'])) {
            throw new \InvalidArgumentException(__('That time slot is full. Please pick another.'));
        }

        return $at;
    }

    /**
     * P6-291: does this branch have a closed branch_hour_exception for the
     * given local date (Y-m-d)? Used by validate() to refuse a pre-order
     * whose own date is closed, even when a spillover generated the slot.
     */
    private function hasClosedException(?Branch $branch, string $date): bool
    {
        if (! $branch) {
            return false;
        }
        $row = null;
        if ($branch->relationLoaded('hourExceptions')) {
            foreach ($branch->hourExceptions as $r) {
                $d = $r->date->format('Y-m-d');
                if ($d === $date) {
                    $row = $r;
                    break;
                }
            }
        } else {
            $row = BranchHourException::query()
                ->where('branch_id', $branch->id)
                ->where('date', $date)
                ->first();
        }

        return $row !== null && (bool) $row->closed;
    }

    /** Kitchen fire time for a scheduled order: scheduled - prep. Null for ASAP. */
    public function computeFireAt(int $storeId, ?Carbon $scheduledAt): ?Carbon
    {
        if ($scheduledAt === null) {
            return null;
        }

        return $scheduledAt->copy()->subMinutes($this->prepMinutes($storeId));
    }

    /**
     * Release a scheduled order to the live kitchen queues. Idempotent: a no-op if
     * the order is ASAP or already released. On first release it stamps released_at
     * and fires the deferred staff "new order" push. Returns true if it released now.
     */
    public function release(Order $order): bool
    {
        if (! $order->isPendingSchedule()) {
            return false;
        }
        $order->released_at = Carbon::now();
        $order->save();

        try {
            // P6-213: title + body render under the order's locale (falling back
            // to the store's default_language). The label is the translated
            // "Table :number" or the localized order type; the amount is the
            // store's own money format (sign + number from store_options, no
            // platform currency leak). The keys are the snake_case IDs every
            // core locale now ships — previously the inline English strings
            // were looked up as keys (i.e. never found) and the push shipped
            // raw English on every store.
            CustomerPushService::inOrderOrStoreLocale($order, function () use ($order) {
                $typeLabel = (string) $order->type !== ''
                    ? ___((string) $order->type)
                    : '';
                $labelText = $order->table_number
                    ? ___('scheduled_table_number', ['number' => (string) $order->table_number])
                    : $typeLabel;
                $moneyText = store_money_plain((float) $order->total, (int) $order->store_id);
                $title = ___('scheduled_order_due_id', ['id' => (string) $order->id]);
                $body = ___('scheduled_order_body', [
                    'label' => $labelText,
                    'total' => $moneyText,
                ]);
                app(MessageQueue::class)->staffPush(
                    (int) $order->store_id,
                    $title,
                    $body,
                    '/staff-app'
                );
            });
        } catch (\Throwable $e) {
            // notification never blocks the release
        }
        // Owner lane (native app + dashboard PWA), linked to the order.
        try {
            $ownerLabel = $order->table_number ? ('Table '.$order->table_number) : ucfirst((string) $order->type);
            app(MobilePush::class)->newOrder($order, trim($ownerLabel.' · '.store_price_symbol((float) $order->total, (int) $order->store_id)));
        } catch (\Throwable $e) {
        }

        return true;
    }

    /**
     * Release every scheduled order whose fire time has arrived. Called by the
     * per-minute orders:release-scheduled tick. Returns the count released.
     *
     * fire_at is a naive wall-clock in each restaurant's own timezone, so we cannot
     * compare it to a single now() in SQL. We prefilter generously (fire_at within
     * the widest possible tz offset of the app clock) then do a precise per-order
     * check in the restaurant's tz — guaranteeing we never release an order early.
     */
    public function releaseDue(?CarbonInterface $now = null): int
    {
        $appNow = $now ?: Carbon::now();
        // Widest real tz span from the app clock (~UTC-12..+14) — a safe prefilter bound.
        $upper = $appNow->copy()->addHours(15)->format('Y-m-d H:i:s');
        $count = 0;

        Order::query()
            ->whereNotNull('scheduled_at')
            ->whereNull('released_at')
            ->whereNotNull('fire_at')
            ->where('fire_at', '<=', $upper)
            ->orderBy('fire_at')
            ->with('branch')
            ->chunkById(200, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    $branch = $order->branch instanceof Branch ? $order->branch : null;
                    $tz = $this->timezone((int) $order->store_id, $branch);
                    $nowLocal = Carbon::now($tz);
                    $fireLocal = Carbon::parse($order->fire_at->format('Y-m-d H:i:s'), $tz);
                    if ($fireLocal->lte($nowLocal) && $this->release($order)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    // --- internals -------------------------------------------------------

    /**
     * Opening window [open,close,closed] for a branch on a given day; restaurant-wide
     * fallback = always open. P6-291: a branch_hour_exceptions row for the day
     * wins over the weekly hours — closed exception → closed, special hours →
     * those hours (overnight allowed, close < open). A branch with no weekly
     * hours but with an exception uses the exception.
     *
     * The window is read by slotsFor() per calendar day, so the closed-exception
     * refusal a slot's own date triggers naturally here — no extra plumbing in
     * the slot loop.
     */
    private function windowFor(?Branch $branch, CarbonInterface $day): array
    {
        if (! $branch) {
            return ['00:00', '23:59', false];
        }

        $dateStr = $day->format('Y-m-d');

        // Exception for the day wins: load the branch's exceptions once per
        // request and prefer the preloaded relation when available.
        $exc = null;
        if ($branch->relationLoaded('hourExceptions')) {
            foreach ($branch->hourExceptions as $row) {
                $d = $row->date->format('Y-m-d');
                if ($d === $dateStr) {
                    $exc = $row;
                    break;
                }
            }
        } else {
            $exc = BranchHourException::query()
                ->where('branch_id', $branch->id)
                ->where('date', $dateStr)
                ->first();
        }

        if ($exc !== null) {
            if ($exc->closed) {
                return ['00:00', '00:00', true];
            }
            $open = $this->validExceptionTime($exc->open);
            $close = $this->validExceptionTime($exc->close);
            if ($open === null || $close === null) {
                return ['00:00', '00:00', true];
            }

            return [$open, $close, false];
        }

        if (empty($branch->hours)) {
            return ['00:00', '23:59', false];
        }

        $key = Branch::DAYS[$day->dayOfWeekIso - 1];
        $h = $branch->dayHours($key);

        return [$h['open'], $h['close'], (bool) $h['closed']];
    }

    /** HH:MM validator for branch_hour_exceptions.open/close; null on bad input. */
    private function validExceptionTime(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Count existing scheduled orders per interval key within [start,end).
     * scheduled_at is stored as a naive wall-clock (the app writes it via Carbon::parse
     * with no tz conversion), so we compare and bucket in the same restaurant tz we
     * generate slots in — never shifting the wall-clock.
     */
    private function slotCounts(int $storeId, ?Branch $branch, CarbonInterface $start, CarbonInterface $end, int $interval): array
    {
        $tz = $start->timezone;
        $q = Order::query()
            ->where('store_id', $storeId)
            ->whereNotNull('scheduled_at')
            // A cancelled pre-order must not keep consuming its slot's capacity — else
            // phantom bookings silently block the slot forever. NULL-safe: only rows
            // explicitly 'cancelled' are dropped (legacy NULL-status orders still count).
            ->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->whereBetween('scheduled_at', [
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            ]);
        if ($branch) {
            $q->where('branch_id', $branch->id);
        }
        $counts = [];
        foreach ($q->pluck('scheduled_at') as $ts) {
            // Interpret the stored wall-clock in the restaurant tz (no conversion).
            $wall = $ts instanceof CarbonInterface ? $ts->format('Y-m-d H:i:s') : (string) $ts;
            $local = Carbon::parse($wall, $tz);
            $mins = (int) floor(($local->hour * 60 + $local->minute) / $interval) * $interval;
            $slot = $local->copy()->startOfDay()->addMinutes($mins);
            $key = $slot->format('Y-m-d H:i');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        // P3-11: places a customer still checking out holds (Laundry's one hold for pickup + delivery) take capacity too
        foreach (app(HeldSlotCounts::class)->pickups($storeId, $branch, $start, $end, $interval) as $key => $held) {
            $counts[$key] = ($counts[$key] ?? 0) + $held;
        }

        // Lane C: + the seats of live schedule-ahead holds (headless API, `pslot_` slots) no order has taken yet —
        // slot_seats.slot_at is the slot start in UTC.
        $seats = DB::table('slot_seats')
            ->join('slot_holds', 'slot_holds.id', '=', 'slot_seats.hold_id')
            ->where('slot_seats.store_id', $storeId)
            ->whereNull('slot_seats.order_id')
            ->where('slot_seats.expires_at', '>', now()->utc()->format('Y-m-d H:i:s'))
            ->where('slot_holds.slot_id', 'like', 'pslot_%')
            ->where('slot_seats.slot_at', '>=', Carbon::parse($start->format('Y-m-d H:i:s'), $tz)->utc()->format('Y-m-d H:i:s'))
            ->where('slot_seats.slot_at', '<', Carbon::parse($end->format('Y-m-d H:i:s'), $tz)->utc()->format('Y-m-d H:i:s'));
        if ($branch) {
            $seats->where('slot_seats.branch_key', $branch->id);
        }
        foreach ($seats->pluck('slot_seats.slot_at') as $at) {
            $local = Carbon::parse((string) $at, 'UTC')->setTimezone($tz);
            $mins = (int) floor(($local->hour * 60 + $local->minute) / $interval) * $interval;
            $key = $local->copy()->startOfDay()->addMinutes($mins)->format('Y-m-d H:i');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** Find the slot whose time equals $at (same Y-m-d H:i), or []. */
    private function matchSlot(array $slots, CarbonInterface $at): array
    {
        $needle = $at->format('Y-m-d H:i');
        foreach ($slots as $s) {
            if ($s['at']->format('Y-m-d H:i') === $needle) {
                return $s;
            }
        }

        return [];
    }
}
