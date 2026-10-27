<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Slots;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Ordering\Data\SlotException;
use Juwri\Modules\Ordering\Models\SlotHold;
use Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments;
use Juwri\Modules\Ordering\Services\Payments\PendingPaymentCancelledNotice;

/**
 * P3-09 (REVIEW, Opus: holds + capacity) — 10-minute slot holds (API-3, API-11) and the capacity guard.
 *
 * The guard is in the database: a held or ordered slot owns one `slot_seats` row numbered 1..capacity and
 * UNIQUE(store_id, branch_key, slot_at, seat_no) lets exactly one of two concurrent takers of the same seat commit (on
 * SQLite, MySQL and PostgreSQL alike). A taker reads the live seats, tries the free seat numbers in order, each attempt
 * in its own transaction (a unique violation or a lock conflict rolls back only that attempt), and gives the slot up
 * as `slot_full` when no number below the capacity is left. Expired hold seats are deleted first, in the same
 * transaction, so an abandoned hold frees its capacity at the next read (and in the sweep).
 *
 * Lifecycle: `active` (10 minutes) → `used` (a pay-later order took it: its seat now belongs to the order, no expiry)
 * or `bound` (a hosted order took it: the seat belongs to the order until its 15-minute payment window ends, API-10)
 * → `used` once paid; `released` by the shopper; `expired` when its time or its order's window ended. A cancelled
 * order frees its seat (Order::updated hook in OrderingServiceProvider).
 */
final class SlotHolds
{
    /** At most this many live holds per holder (storefront token + IP), so nobody can block a calendar (README §2.8). */
    public const MAX_LIVE = 3;

    public function __construct(private readonly DeliverySlots $slots, private readonly SchedulingSlots $scheduled) {}

    /**
     * A slot of either source by its id: a food schedule-ahead slot (`pslot_`, SchedulingSlots) or a rule slot.
     *
     * @return array<string, mixed>|null
     */
    public function findSlot(Store $store, ?Branch $branch, string $slotId): ?array
    {
        return SchedulingSlots::isOwn($slotId) ? $this->scheduled->find($store, $branch, $slotId) : $this->slots->find($store, $branch, $slotId);
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>|null
     */
    public function nextFreeSlot(Store $store, array $slot): ?array
    {
        return ($slot['source'] ?? null) === 'scheduling' ? $this->scheduled->nextFree($store, $slot) : $this->slots->nextFree($store, $slot);
    }

    /**
     * Hold a slot. With an idempotency key, the same key and slot answer the first hold again (`replayed`); the same
     * key for another slot is a conflict.
     *
     * @return array{hold: SlotHold, token: string, slot: array<string, mixed>, replayed: bool}
     *
     * @throws SlotException
     */
    public function hold(Store $store, ?Branch $branch, string $slotId, ?string $holderKey, ?string $idempotencyKey = null): array
    {
        $requestHash = hash('sha256', 'delivery_slot|'.$slotId);
        if ($idempotencyKey !== null) {
            $previous = SlotHold::query()->where('store_id', $store->id)->where('idempotency_key', $idempotencyKey)->orderByDesc('id')->first();
            if ($previous !== null) {
                if ($previous->request_hash !== $requestHash) {
                    throw new SlotException('idempotency_conflict', ___('This request key was already used for a different request.'), 409);
                }
                $slot = $this->findSlot($store, $branch, $slotId);
                $token = $previous->token_encrypted !== null ? (string) Crypt::decryptString((string) $previous->token_encrypted) : '';
                if ($slot !== null && $token !== '' && $previous->isLive()) {
                    return ['hold' => $previous, 'token' => $token, 'slot' => $slot, 'replayed' => true];
                }
            }
        }

        $slot = $this->findSlot($store, $branch, $slotId);
        if ($slot === null) {
            throw SlotException::invalid('slot_id', ___('This time is not available. Pick another time.'));
        }

        if ($holderKey !== null) {
            $live = SlotHold::query()->where('holder_key', $holderKey)->where('status', 'active')->where('expires_at', '>', now())->count();
            if ($live >= self::MAX_LIVE) {
                throw SlotException::tooMany();
            }
        }

        $token = 'hld_'.Str::lower(Str::random(32));
        $expires = now()->addMinutes(SlotHold::MINUTES);
        $hold = $this->takeSeat($store, $slot, function () use ($store, $slot, $slotId, $token, $holderKey, $idempotencyKey, $requestHash, $expires) {
            return SlotHold::query()->create([
                'store_id' => (int) $store->id,
                'branch_id' => ($slot['branch'] ?? null)?->id,
                'order_type' => (string) $slot['order_type'],
                'slot_id' => $slotId,
                'slot_starts_at' => $slot['starts_at'],
                'slot_ends_at' => $slot['ends_at'],
                'expires_at' => $expires,
                'token_hash' => hash('sha256', $token),
                'token_encrypted' => $idempotencyKey !== null ? Crypt::encryptString($token) : null,
                'request_hash' => $requestHash,
                'holder_key' => $holderKey,
                'idempotency_key' => $idempotencyKey,
                'status' => 'active',
            ]);
        }, $expires);
        if ($hold === null) {
            throw SlotException::full($this->nextFreeSlot($store, $slot));
        }

        return ['hold' => $hold, 'token' => $token, 'slot' => $slot, 'replayed' => false];
    }

    /**
     * The shopper's release (`DELETE /holds/{id}` with its token). A live hold frees its seat. A hold bound to a pending
     * hosted order answers `hold_in_use` unless the store has "Release holds during payment" on (API-24a); then the
     * order is cancelled (its payment stops) and the slot freed, and the customer gets the payment-not-completed
     * message only when the store turned that on (API-29).
     *
     * @return Order|null the order this release cancelled
     *
     * @throws SlotException
     */
    public function release(Store $store, string $holdId, ?string $token): ?Order
    {
        $hold = SlotHold::query()->where('store_id', $store->id)->where('public_id', $holdId)->first();
        if ($hold === null || ! $hold->tokenMatches($token)) {
            throw SlotException::notFound();
        }
        if ($hold->status === 'bound' && $hold->order_id !== null) {
            $order = Order::query()->where('store_id', $store->id)->whereKey($hold->order_id)->first();
            if ($order !== null && $order->status === 'unpaid' && (int) $order->is_paid !== 1) {
                if (! StoreSettings::bool((int) $store->id, 'holds_release_during_payment')) {
                    throw SlotException::inUse();
                }
                app(HostedOrderPayments::class)->cancelByCustomer($order); // the Order hook frees the seat
                $this->releaseForOrder($order->fresh() ?? $order);
                app(PendingPaymentCancelledNotice::class)->send($order->fresh() ?? $order);

                return $order->fresh();
            }
        }
        if ($hold->status !== 'active' || ! $hold->expires_at->isFuture()) {
            throw SlotException::notFound();
        }
        DB::transaction(function () use ($hold) {
            $updated = SlotHold::query()->whereKey($hold->id)->where('status', 'active')->update(['status' => 'released', 'updated_at' => now()]);
            if ($updated === 1) {
                DB::table('slot_seats')->where('hold_id', $hold->id)->whereNull('order_id')->delete();
            }
        });

        return null;
    }

    /**
     * Before an order submit (outside its transaction): the hold the cart names must be this store's, with the right
     * token, for the cart's slot. A hold whose 10 minutes ended (or that the shopper released) is taken again when its
     * slot still has room — the order goes ahead; when the slot is full the submit answers 409 `slot_full` with the
     * next free slot. A hold another order already took is `hold_expired`.
     *
     * @throws SlotException
     */
    public function ensureLive(Store $store, ?Branch $branch, string $holdId, ?string $token, ?string $slotId): SlotHold
    {
        $hold = SlotHold::query()->where('store_id', $store->id)->where('public_id', $holdId)->first();
        if ($hold === null || ! $hold->tokenMatches($token)) {
            throw SlotException::holdExpired();
        }
        if ($slotId !== null && $slotId !== '' && $slotId !== $hold->slot_id) {
            throw SlotException::invalid('slot_id', ___('The held time is not the time in this order.'));
        }
        if ($hold->status === 'active' && $hold->expires_at->isFuture()) {
            return $hold;
        }
        if (! in_array($hold->status, ['active', 'expired', 'released'], true) || $hold->order_id !== null) {
            throw SlotException::holdExpired();
        }
        $slot = $this->findSlot($store, $branch, $hold->slot_id);
        if ($slot === null) {
            throw SlotException::holdExpired(); // the time has passed or is no longer offered
        }
        $expires = now()->addMinutes(SlotHold::MINUTES);
        $revived = $this->takeSeat($store, $slot, function () use ($hold, $expires) {
            DB::table('slot_seats')->where('hold_id', $hold->id)->whereNull('order_id')->delete();
            SlotHold::query()->whereKey($hold->id)->update(['status' => 'active', 'expires_at' => $expires, 'updated_at' => now()]);

            return $hold->fresh() ?? $hold;
        }, $expires);
        if ($revived === null) {
            throw SlotException::full($this->nextFreeSlot($store, $slot));
        }

        return $revived;
    }

    /**
     * The live hold an order submit names, locked for the order's transaction: this store's, the right token, still
     * active and not past its 10 minutes, for the slot the cart names (when it names one).
     *
     * @throws SlotException
     */
    public function lockForOrder(Store $store, string $holdId, ?string $token, ?string $slotId): SlotHold
    {
        $hold = SlotHold::query()->where('store_id', $store->id)->where('public_id', $holdId)->lockForUpdate()->first();
        if ($hold === null || ! $hold->tokenMatches($token)) {
            throw SlotException::holdExpired();
        }
        if ($hold->status !== 'active' || ! $hold->expires_at->isFuture()) {
            throw SlotException::holdExpired();
        }
        if ($slotId !== null && $slotId !== '' && $slotId !== $hold->slot_id) {
            throw SlotException::invalid('slot_id', ___('The held time is not the time in this order.'));
        }

        return $hold;
    }

    /**
     * Hand the hold's seat to a placed order (inside the order's transaction): a pay-later order owns it from now on;
     * a hosted order owns it until its payment window ends (`$windowEnd`), then the order is cancelled if unpaid.
     */
    public function bind(SlotHold $hold, Order $order, ?CarbonInterface $windowEnd): void
    {
        $hosted = $windowEnd !== null;
        SlotHold::query()->whereKey($hold->id)->update([
            'status' => $hosted ? 'bound' : 'used',
            'order_id' => $order->id,
            'expires_at' => $hosted ? CarbonImmutable::instance($windowEnd)->utc() : $hold->expires_at,
            'updated_at' => now(),
        ]);
        DB::table('slot_seats')->where('hold_id', $hold->id)->update([
            'order_id' => $order->id,
            'expires_at' => $hosted ? CarbonImmutable::instance($windowEnd)->utc()->format('Y-m-d H:i:s') : null,
        ]);
        $order->forceFill([
            'slot_hold_id' => $hold->id,
            'slot_starts_at' => $hold->slot_starts_at,
            'slot_ends_at' => $hold->slot_ends_at,
        ])->save();
    }

    /** A hosted order was paid (or its card hold authorised): its slot is the order's for good. */
    public function settleForOrder(Order $order): void
    {
        DB::table('slot_seats')->where('store_id', $order->store_id)->where('order_id', $order->id)->update(['expires_at' => null]);
        SlotHold::query()->where('store_id', $order->store_id)->where('order_id', $order->id)->where('status', 'bound')
            ->update(['status' => 'used', 'updated_at' => now()]);
    }

    /** A cancelled order gives its slot back. Idempotent. */
    public function releaseForOrder(Order $order): void
    {
        DB::table('slot_seats')->where('store_id', $order->store_id)->where('order_id', $order->id)->delete();
        SlotHold::query()->where('store_id', $order->store_id)->where('order_id', $order->id)->whereIn('status', ['bound', 'active'])
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    /**
     * API-27: the owner accepted a late payment of a cancelled slotted order: take a seat of its slot again for good.
     * True when the order has no slot or its seat is back; false when the slot passed, is no longer offered or is full
     * (the order then waits as `pending_approval`).
     */
    public function retakeForOrder(Order $order): bool
    {
        $holdId = $order->getAttribute('slot_hold_id');
        if ($holdId === null) {
            return true;
        }
        if (DB::table('slot_seats')->where('store_id', $order->store_id)->where('order_id', $order->id)->exists()) {
            return true; // never given back
        }
        $hold = SlotHold::query()->where('store_id', $order->store_id)->whereKey((int) $holdId)->first();
        $store = Store::query()->find((int) $order->store_id);
        if ($hold === null || $store === null) {
            return false;
        }
        $branch = $hold->branch_id !== null ? Branch::query()->where('store_id', $store->id)->whereKey($hold->branch_id)->first() : null;
        $slot = $this->findSlot($store, $branch, (string) $hold->slot_id);
        if ($slot === null) {
            return false; // the time has passed or is no longer offered
        }
        $expires = now()->addMinutes(SlotHold::MINUTES);
        $taken = $this->takeSeat($store, $slot, function () use ($hold) {
            SlotHold::query()->whereKey($hold->id)->update(['status' => 'used', 'updated_at' => now()]);

            return $hold->fresh() ?? $hold;
        }, $expires);
        if ($taken === null) {
            return false;
        }
        DB::table('slot_seats')->where('hold_id', $hold->id)->update(['order_id' => $order->id, 'expires_at' => null]);

        return true;
    }

    /** Clear expired holds and their seats (all stores; the per-minute sweep). */
    public function sweep(): int
    {
        return TenantContext::bypass(function () {
            $now = now();
            SlotHold::query()->where('status', 'active')->where('expires_at', '<=', $now)->update(['status' => 'expired', 'updated_at' => $now]);

            return DB::table('slot_seats')->whereNull('order_id')->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now->copy()->utc()->format('Y-m-d H:i:s'))->delete();
        });
    }

    /**
     * Take a seat of the slot for the hold `$create` makes, or null when the slot has no seat left. Capacity 0 = no
     * limit: the hold is made without a seat.
     *
     * @param  array<string, mixed>  $slot
     * @param  callable(): SlotHold  $create
     */
    private function takeSeat(Store $store, array $slot, callable $create, CarbonInterface $expires): ?SlotHold
    {
        $capacity = (int) $slot['capacity'];
        if ($capacity <= 0) {
            return DB::transaction(fn () => $create());
        }
        if (($slot['source'] ?? null) === 'scheduling') {
            // Lane C: a food schedule-ahead slot is also filled by scheduled orders that own no seat (web, POS, API
            // without a hold): take the seat under the order paths' per-slot lock and count those orders too.
            $lock = Cache::lock($this->scheduled->lockKey($store, $slot), 15);
            try {
                $lock->block(5);
            } catch (LockTimeoutException) {
                return null;
            }
            try {
                return $this->takeSeatWithin($store, $slot, $create, $expires, fn () => $this->scheduled->usage($store, $slot));
            } finally {
                $lock->release();
            }
        }

        return $this->takeSeatWithin($store, $slot, $create, $expires, null);
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  callable(): SlotHold  $create
     * @param  (callable(): int)|null  $occupied  the slot's occupancy (default: its seats)
     */
    private function takeSeatWithin(Store $store, array $slot, callable $create, CarbonInterface $expires, ?callable $occupied): ?SlotHold
    {
        $capacity = (int) $slot['capacity'];
        $branchKey = (int) $slot['branch_key'];
        $slotAt = CarbonImmutable::instance($slot['starts_at'])->utc()->format('Y-m-d H:i:s');
        $nowUtc = now()->utc()->format('Y-m-d H:i:s');

        // Every attempt is a whole transaction: a unique violation (another taker committed that seat first) or a
        // lock conflict rolls the attempt back, then the free seats are read again. Bounded: capacity + 2 attempts.
        for ($attempt = 0; $attempt < $capacity + 2; $attempt++) {
            try {
                $result = DB::transaction(function () use ($store, $branchKey, $slotAt, $nowUtc, $capacity, $create, $expires, $occupied) {
                    DB::table('slot_seats')->where('store_id', $store->id)->where('branch_key', $branchKey)->where('slot_at', $slotAt)
                        ->whereNull('order_id')->whereNotNull('expires_at')->where('expires_at', '<=', $nowUtc)->delete();
                    $used = DB::table('slot_seats')->where('store_id', $store->id)->where('branch_key', $branchKey)->where('slot_at', $slotAt)
                        ->pluck('seat_no')->map(fn ($n) => (int) $n)->all();
                    if (count($used) >= $capacity || ($occupied !== null && $occupied() >= $capacity)) {
                        return false; // full (also when the owner lowered the capacity below the seats taken)
                    }
                    $seat = null;
                    for ($n = 1; $n <= $capacity; $n++) {
                        if (! in_array($n, $used, true)) {
                            $seat = $n;
                            break;
                        }
                    }
                    if ($seat === null) {
                        return false;
                    }
                    $hold = $create();
                    DB::table('slot_seats')->insert([
                        'account_id' => (int) $store->account_id,
                        'store_id' => (int) $store->id,
                        'branch_key' => $branchKey,
                        'slot_at' => $slotAt,
                        'seat_no' => $seat,
                        'hold_id' => $hold->id,
                        'order_id' => null,
                        'expires_at' => CarbonImmutable::instance($expires)->utc()->format('Y-m-d H:i:s'),
                    ]);

                    return $hold;
                });
            } catch (UniqueConstraintViolationException) {
                continue; // the seat went to a concurrent taker: read the free seats again
            } catch (QueryException $e) {
                if (! self::isLockConflict($e)) {
                    throw $e;
                }

                continue;
            }

            return $result instanceof SlotHold ? $result : null;
        }

        return null;
    }

    /** MySQL 1205/1213, PostgreSQL 40P01/40001/55P03: a lock conflict, not a bug. */
    private static function isLockConflict(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $code = (int) ($e->errorInfo[1] ?? 0);

        return in_array($code, [1205, 1213], true) || in_array($state, ['40001', '40P01', '55P03'], true);
    }
}
