<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel;

use App\Models\Order;
use App\Models\StoreTable;
use App\Services\CustomerWalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Money\Money;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelEvent;
use Juwri\Modules\Hotel\Models\HotelExtraService;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Services\Hotel\Pms\PmsManager;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;

/**
 * FEATURES-RUN-4 T1 / H6 — charge-to-room folio.
 *
 * A guest's folio is NOT a parallel ledger: it is a {@see LedgerAccount} of kind
 * `hotel_folio` on the SAME unified double-entry ledger that Coupons & Wallets
 * shipped ({@see LedgerService}, {@see CustomerWalletService}). One folio account
 * per stay, keyed (owner_type=`hotel_stay`, owner_id=stay.id, kind=`hotel_folio`).
 *
 * Sign convention (accounts-receivable): the folio balance is what the guest
 * OWES. A room-service order posts a POSITIVE amount onto the folio (guest now
 * owes more); a settlement posts a NEGATIVE amount (guest paid, balance drops
 * toward zero). Because LedgerService forbids a non-source account from going
 * negative, an over-settlement is rejected automatically. Every charge is
 * balanced against a per-restaurant `hotel_folio_clearing` account (allowed to
 * run negative, like the platform clearing account for store credit).
 *
 * Reuse map: LedgerService::post / accountFor / balanceFor (the ledger),
 * HotelStay (the stay + folio_account_id pointer), HotelService (hotel_mode gate).
 *
 * A7 — currency decimals: folio amounts round with `currency_decimals($folio->currency)`, the way
 * Order::getAmountDueAttribute rounds the SAME order total. Bug batch 4 · 1 ended the old limitation: the shared
 * ledger now posts every leg at its account currency's ISO exponent (LedgerService::legMinor), so a 12.345 KWD
 * charge is stored as 12.345 and the folio balance equals the sum of the order totals to the fil.
 *
 * P1-05 (slice 10a-2): every rounding goes through Money::round (HALF_UP on the value's 15 significant digits —
 * PHP-version independent) at the SAME display decimals as before; the ledger leg precision stays LedgerService's.
 */
class HotelFolioService
{
    /** The currency a restaurant's folios are denominated in (its own, not the ambient request currency). */
    public static function currencyFor(int $storeId): string
    {
        $code = store_options($storeId, 'currency_code');

        return $code ? strtoupper((string) $code) : app(Ledger::class)->defaultCurrency();
    }

    /**
     * Get-or-create the folio ledger account for a stay (kind hotel_folio).
     *
     * A6: the account is PINNED at check-in via `folio_account_id` and its currency
     * is fixed at open. Resolving by the store's LIVE currency instead (the old
     * behaviour) meant a mid-stay currency change spawned a fresh EMPTY account in
     * the new currency — balance()/statement() then read zero, and checkOut() passed
     * its zero-balance gate, letting the guest walk out while the real charges sat
     * orphaned on the original account. Always prefer the pinned pointer; only derive
     * (and write the pointer back) for a legacy stay that never stored one.
     */
    public static function folioAccountFor(HotelStay $stay): LedgerAccount
    {
        if ($stay->folio_account_id) {
            $acct = LedgerAccount::find($stay->folio_account_id);
            if ($acct) {
                return $acct;
            }
        }

        $acct = app(Ledger::class)->accountFor(
            'hotel_stay',
            (int) $stay->id,
            'hotel_folio',
            static::currencyFor((int) $stay->store_id)
        );

        // Pin the pointer so every later read resolves the SAME account regardless of
        // a store currency change (makes folio_account_id the single source of truth).
        if (! $stay->folio_account_id) {
            $stay->folio_account_id = $acct->id;
            $stay->save();
        }

        return $acct;
    }

    /** The per-restaurant clearing account every folio charge/settlement balances against. */
    protected static function clearingFor(int $storeId, string $currency): LedgerAccount
    {
        return app(Ledger::class)->accountFor('restaurant', $storeId, 'hotel_folio_clearing', $currency);
    }

    /**
     * Check a guest into a room. Creates the stay AND opens its folio ledger
     * account, storing the account id on the stay (`folio_account_id`). The room
     * is a `store_tables` row (area_type='room' by convention — not enforced
     * here so a plain table can host a stay in dev).
     */
    public static function checkIn(
        int $storeId,
        StoreTable $room,
        string $guestName,
        ?string $guestPhone = null,
        ?int $branchId = null
    ): HotelStay {
        return DB::transaction(function () use ($storeId, $room, $guestName, $guestPhone, $branchId) {
            // A4: re-check for an existing active stay INSIDE the transaction so two
            // racing check-ins (double-click, two front-desk tabs) do not both create
            // a stay + folio on one room. sqlite lockForUpdate is a no-op, so this is
            // a best-effort sequential guard; a unique index is the durable backstop
            // (out of scope for this zero-migration run).
            if (static::activeStayForRoom($storeId, (int) $room->id)) {
                throw new \RuntimeException('That room already has an active stay.');
            }

            $stay = HotelStay::create([
                'store_id' => $storeId,
                'branch_id' => $branchId ?: ($room->branch_id ?: null),
                'table_id' => $room->id,
                'guest_name' => trim($guestName),
                'guest_phone' => $guestPhone,
                'status' => 'checked_in',
                'checked_in_at' => Carbon::now(),
                'token' => Str::random(48),
            ]);

            $folio = static::folioAccountFor($stay);
            $stay->folio_account_id = $folio->id;
            $stay->save();

            return $stay->refresh();
        });
    }

    /** The active (checked-in) stay for a room, or null. */
    public static function activeStayForRoom(int $storeId, int $tableId): ?HotelStay
    {
        return HotelStay::where('store_id', $storeId)
            ->where('table_id', $tableId)
            ->where('status', 'checked_in')
            ->latest('id')
            ->first();
    }

    /**
     * Guest-name match for charge-to-room: case-insensitive, whitespace-collapsed, the FULL registered name only.
     * Blank input never matches.
     *
     * SECURITY: J57 (owner) made the scanned room token the primary gate (required by default) and dropped the
     * first-name-only convenience ("Sam" for "Sam Carter"): a first name is easy to guess, and where an owner turns
     * the token off this name is the only secret. History: a prefix match ("S") once collapsed the gate to a
     * ~1-in-26 guess; then the exact first name was still accepted. Now: full name, exact.
     */
    public static function guestNameMatches(HotelStay $stay, ?string $name): bool
    {
        $given = preg_replace('/\s+/', ' ', Str::lower(trim((string) $name)));
        $onFile = preg_replace('/\s+/', ' ', Str::lower(trim((string) $stay->guest_name)));

        return $given !== '' && $onFile !== '' && hash_equals($onFile, $given);
    }

    /** Outstanding folio balance (what the guest owes). */
    public static function balance(HotelStay $stay): float
    {
        $folio = static::folioAccountFor($stay);

        return Money::round(app(Ledger::class)->accountBalance($folio), money_decimals($folio->currency));
    }

    /**
     * The `charge_to_room` payment path (H6). Post a room-service order's total
     * onto the guest's folio instead of collecting payment. Idempotent per order
     * via ref `hotel_folio:order:{id}` — calling twice for one order is a no-op.
     * Marks the order paid-to-folio (`payment_method='charge_to_room'`). Returns
     * the ledger transaction id. Throws if the stay is not active.
     */
    public static function chargeOrder(Order $order, HotelStay $stay, ?float $amount = null): string
    {
        // A4: the stay was resolved at order validation, hundreds of lines before
        // this post. Re-read its row so a charge racing a front-desk check-out does
        // NOT land on an already-settled folio (LedgerService::post itself never
        // checks account status). sqlite lockForUpdate is a no-op, so this is a
        // best-effort re-read rather than a hard lock.
        $stay->refresh();
        if (! $stay->isActive()) {
            throw new \RuntimeException('Cannot charge to a stay that is not checked in.');
        }

        $folio = static::folioAccountFor($stay);
        $clearing = static::clearingFor((int) $stay->store_id, $folio->currency);

        // A7: round to the FOLIO account's currency decimals (not a hardcoded 2), so
        // the folio charge matches how Order::getAmountDueAttribute rounds the same
        // order total (the ledger posts at that precision too — bug batch 4 · 1).
        $amount = Money::round($amount !== null ? $amount : (float) $order->total, money_decimals($folio->currency));
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Charge amount must be positive.');
        }

        // A6 (documented limitation): the folio is pinned to its open currency. If the
        // store currency has since changed, the order total posts as a NUMERIC amount
        // in the folio's original currency (no FX conversion this run) — leave an audit
        // note so the divergence is visible on the stay.
        if (static::currencyFor((int) $stay->store_id) !== $folio->currency) {
            log_activity('hotel.folio.cross_currency_charge', $order, [
                'order_id' => $order->id, 'stay_id' => $stay->id,
                'folio_currency' => $folio->currency,
                'store_currency' => static::currencyFor((int) $stay->store_id),
            ], (int) $stay->store_id);
        }

        $ref = 'hotel_folio:order:'.$order->id;
        $reason = 'Room-service order #'.$order->id;

        $txId = app(Ledger::class)->post([
            ['account' => $clearing, 'amount' => -$amount, 'allow_negative' => true, 'ref' => $ref.':clr', 'reason' => $reason],
            ['account' => $folio, 'amount' => $amount, 'ref' => $ref.':folio', 'reason' => $reason,
                'meta' => ['order_id' => $order->id, 'stay_id' => $stay->id, 'table_id' => $stay->table_id]],
        ], null, $reason);

        // Mark the order tendered to the folio (no gateway/cash collection). We
        // only stamp `payment_method` — the `orders.status` enum is a fixed set
        // (pending|completed|unpaid) and must not gain a new value here, so the
        // folio ledger itself is the authoritative "paid to room" record.
        $order->payment_method = 'charge_to_room';
        $order->save();

        // H10 PMS seam: mirror the charge onto the property-management folio when a
        // live PMS driver is configured. NullPmsConnector (the shipping default) is
        // a no-op, so the local unified-ledger folio stays authoritative. Best-effort
        // — a PMS outage must never fail an in-house order.
        try {
            PmsManager::for((int) $stay->store_id)->postCharge($stay, $order);
        } catch (\Throwable $e) {
            Log::warning('PMS postCharge failed', ['stay' => $stay->id, 'order' => $order->id, 'error' => $e->getMessage()]);
        }

        return $txId;
    }

    /**
     * A3 — the live reversal state of an order's folio charge, for callers that
     * must decide whether to reverse (cancel path) or refuse (refund-to-credit),
     * and for the dashboard's "needs manual adjustment" badge.
     *
     *   'none'     — the order was never charged to a folio.
     *   'charged'  — charged and NOT reversed (a live receivable on the folio).
     *   'reversed' — the charge has already been reversed (cancel took effect).
     */
    public static function orderChargeReversalState(Order $order): string
    {
        $charge = LedgerEntry::where('ref', 'hotel_folio:order:'.$order->id.':folio')->first();
        if (! $charge) {
            return 'none';
        }
        $reversed = LedgerEntry::where('ref', 'hotel_folio:order:'.$order->id.':rev:folio')->exists();

        return $reversed ? 'reversed' : 'charged';
    }

    /**
     * A3 — reverse a charge-to-room order's folio charge when it is cancelled or
     * refunded. Posts a fresh balanced pair (folio → clearing) keyed
     * `hotel_folio:order:{id}:rev`, idempotent via the ledger's unique (account,ref)
     * — a double-cancel reverses exactly once. The reversal amount is the ORIGINAL
     * posted charge (read off the ledger entry), not the order's live total, which
     * may since have drifted.
     *
     * The folio leg is NOT allow_negative on purpose: if the folio was already
     * settled below this order's amount the ledger rejects the reversal and this
     * returns 'pending' — the caller logs an activity + the order is left flagged
     * (orderChargeReversalState stays 'charged') for a manual adjustment (A5).
     * Never silently skips.
     *
     * @return array{state: string, amount?: float, tx?: string, stay_id?: int}
     */
    public static function reverseOrderCharge(Order $order): array
    {
        $charge = LedgerEntry::where('ref', 'hotel_folio:order:'.$order->id.':folio')->first();
        if (! $charge) {
            return ['state' => 'none'];
        }
        $stayId = $charge->meta['stay_id'] ?? null;
        $stay = $stayId ? HotelStay::find($stayId) : null;
        if (! $stay) {
            return ['state' => 'none'];
        }

        $folio = static::folioAccountFor($stay);
        $amount = Money::round(abs((float) $charge->amount), money_decimals($folio->currency));
        if ($amount <= 0) {
            return ['state' => 'none'];
        }

        $clearing = static::clearingFor((int) $stay->store_id, $folio->currency);
        $ref = 'hotel_folio:order:'.$order->id.':rev';
        $reason = 'Order #'.$order->id.' cancelled — folio charge reversed';

        try {
            $txId = app(Ledger::class)->post([
                ['account' => $folio, 'amount' => -$amount, 'ref' => $ref.':folio', 'reason' => $reason,
                    'meta' => ['order_id' => $order->id, 'stay_id' => $stay->id, 'reversal' => true]],
                ['account' => $clearing, 'amount' => $amount, 'allow_negative' => true, 'ref' => $ref.':clr', 'reason' => $reason],
            ], null, $reason);

            return ['state' => 'reversed', 'amount' => $amount, 'tx' => $txId, 'stay_id' => (int) $stay->id];
        } catch (\RuntimeException $e) {
            // Non-negative guard rejected: the folio was already settled below this
            // order's amount. Not silent — caller logs + the order stays flagged.
            return ['state' => 'pending', 'amount' => $amount, 'stay_id' => (int) $stay->id];
        }
    }

    /**
     * Stamp an order as tendered to the room WITHOUT posting a folio charge. Used
     * when the order's outstanding amount is already zero (wallet / gift-card
     * tenders covered it in full at placement): nothing is owed to the folio, but
     * the order is still "paid to room" for the owner/KDS surfaces. No ledger post
     * and no PMS mirror — there is no receivable to mirror.
     */
    public static function markChargedToRoom(Order $order, HotelStay $stay): void
    {
        if (! $stay->isActive()) {
            throw new \RuntimeException('Cannot charge to a stay that is not checked in.');
        }
        $order->payment_method = 'charge_to_room';
        $order->save();
    }

    /**
     * A1/A2/A10 — the single charge-to-room tender entry point used by the order
     * flow. Charges the folio the order's OUTSTANDING amount (amount_due), NOT the
     * gross total: wallet / gift-card / marketplace-wallet tenders were already
     * spent and recorded at placement, so posting the un-reduced total double-bills
     * the guest (they pay the credit portion twice). When amount_due is zero the
     * order is stamped charged-to-room with no ledger post.
     *
     * Returns the guest-facing response contract:
     *   ['charged_to_room' => bool, 'room_folio_message' => string]
     *
     * A folio failure never breaks the placed order — it is caught here, the owner
     * is alerted (A10), and the guest is told to settle at reception (C2 consumes
     * `charged_to_room=false`).
     *
     * @return array{charged_to_room: bool, room_folio_message: string}
     */
    public static function chargeOrderForTender(Order $order, HotelStay $stay): array
    {
        try {
            // fresh() makes the tenders written during pricing visible.
            $amountDue = max(0.0, (float) $order->fresh()->amount_due);
            if ($amountDue > 0.0) {
                static::chargeOrder($order, $stay, $amountDue);
            } else {
                static::markChargedToRoom($order, $stay);
            }

            // A2 belt-and-braces: a residual 'unpaid' stamp (pay_online radio left
            // ticked alongside charge-to-room) would exclude the order from KDS and
            // every owner list. The tender is the folio now — put the order back on
            // the normal flow.
            if ($order->status === 'unpaid') {
                $order->status = 'pending';
                $order->save();
            }

            return ['charged_to_room' => true, 'room_folio_message' => ___('Charged to your room.')];
        } catch (\Throwable $e) {
            Log::warning('charge_to_room failed', [
                'order' => $order->id, 'stay' => $stay->id, 'error' => $e->getMessage(),
            ]);

            // A10: the order stands but no tender was collected — alert the owner so
            // front desk collects another way before the guest leaves.
            try {
                CreateNotificationJob::dispatch(
                    // the translation of this key may or may not end in "#" (the German one does not): normalise, then add the sign
                    rtrim((string) ___('Room charge FAILED — order #'), '# ').' #'.$order->id,
                    'hotel_folio',
                    Route::has('stores.orders') ? route('stores.orders') : null
                );
            } catch (\Throwable $ignore) {
                // notification is best-effort; never let it break the order response.
            }

            return [
                'charged_to_room' => false,
                'room_folio_message' => ___('Order placed — we could not add it to your room bill. Please pay at reception.'),
            ];
        }
    }

    /**
     * Record a settlement against the folio (POS cash/card, or an online link).
     * Partial settlement is allowed. Reduces the outstanding balance; an amount
     * larger than the balance is rejected by the ledger's non-negative guard.
     * `$ref` idempotency-keys the settlement (auto-generated when omitted).
     * Returns the ledger transaction id.
     */
    public static function settle(
        HotelStay $stay,
        float $amount,
        string $method = 'cash',
        ?string $ref = null,
        ?int $grantedBy = null
    ): string {
        $folio = static::folioAccountFor($stay);
        $clearing = static::clearingFor((int) $stay->store_id, $folio->currency);
        $amount = Money::round($amount, money_decimals($folio->currency)); // A7: currency-aware
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Settlement amount must be positive.');
        }
        $ref = 'hotel_folio:settle:'.($ref ?: (string) Str::uuid());
        $reason = 'Folio settlement ('.$method.')';

        return app(Ledger::class)->post([
            ['account' => $folio, 'amount' => -$amount, 'ref' => $ref.':folio', 'reason' => $reason,
                'granted_by' => $grantedBy, 'meta' => ['method' => $method, 'stay_id' => $stay->id]],
            ['account' => $clearing, 'amount' => $amount, 'allow_negative' => true, 'ref' => $ref.':clr', 'reason' => $reason, 'granted_by' => $grantedBy],
        ], null, $reason);
    }

    /**
     * A5 — a manual folio adjustment: a signed miscellaneous charge or credit that
     * is neither a room-service order nor a settlement. A POSITIVE amount posts a
     * charge (minibar, laundry, late checkout, damages — folio balance rises); a
     * NEGATIVE amount posts a credit/void (an erroneous charge is backed out —
     * folio balance falls). The folio leg of a credit is NOT allow_negative, so the
     * ledger rejects an over-credit that would drive the balance below zero. Every
     * adjustment carries the required human reason and the granting user. Idempotent
     * per submission via the caller's `$ref` (auto-generated when omitted). Returns
     * the ledger transaction id.
     */
    public static function adjust(
        HotelStay $stay,
        float $signedAmount,
        string $reason,
        ?int $grantedBy = null,
        ?string $ref = null
    ): string {
        $folio = static::folioAccountFor($stay);
        $signedAmount = Money::round($signedAmount, money_decimals($folio->currency));
        if ($signedAmount == 0.0) {
            throw new \InvalidArgumentException('Adjustment amount must be non-zero.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('An adjustment reason is required.');
        }

        $clearing = static::clearingFor((int) $stay->store_id, $folio->currency);
        $ref = 'hotel_folio:adjust:'.($ref ?: (string) Str::uuid());
        $label = 'Folio adjustment: '.$reason;

        return app(Ledger::class)->post([
            ['account' => $folio, 'amount' => $signedAmount, 'ref' => $ref.':folio', 'reason' => $label,
                'granted_by' => $grantedBy, 'meta' => ['stay_id' => $stay->id, 'adjustment' => true, 'reason' => $reason]],
            ['account' => $clearing, 'amount' => -$signedAmount, 'allow_negative' => true, 'ref' => $ref.':clr', 'reason' => $label, 'granted_by' => $grantedBy],
        ], null, $label);
    }

    /**
     * Check a guest out. Requires a zero folio balance unless `$writeOff` is
     * passed (an explicit balance write-off — H7 will gate this on a permission).
     * Closes the folio account and marks the stay checked_out. Returns the stay.
     */
    public static function checkOut(HotelStay $stay, bool $writeOff = false, ?int $grantedBy = null, ?string $actualCheckoutDate = null): HotelStay
    {
        // A4: refuse a second check-out on an already-closed stay. With the old
        // fixed write-off ref a re-run silently no-oped and reported success while a
        // residual late charge stood forever; refusing here (the controller turns it
        // into a friendly 422) makes that impossible.
        $stay->refresh();
        if ($stay->status === 'checked_out') {
            throw new \RuntimeException('This stay is already checked out.');
        }

        // HPMS A6 — booking handoff. When a PMS booking is linked to this stay,
        // an EARLY departure (before the booked check-out) reverses the nights the
        // guest never stayed BEFORE the zero-balance gate, so the folio reflects the
        // shortened stay. A walk-in stay (no linked booking) is unaffected.
        $booking = HotelBooking::where('hotel_stay_id', $stay->id)->first();
        if ($booking) {
            $departure = $actualCheckoutDate ?: Carbon::now()->toDateString();
            $checkInDate = Carbon::parse($booking->check_in)->toDateString();
            if ($departure < $checkInDate) {
                $departure = $checkInDate; // never reverse a night before arrival
            }
            static::reverseUnstayedNights($stay, $departure);
        }

        $balance = static::balance($stay);

        if ($balance > 0.001) {
            if (! $writeOff) {
                throw new \RuntimeException('Folio balance of '.$balance.' must be settled before check-out.');
            }
            // Write off the remaining balance to zero. A4: the ref is unique per
            // attempt (uuid) so a legitimate later write-off of a late charge is
            // never swallowed by the ledger's (account,ref) idempotency the way the
            // old constant ref was.
            $folio = static::folioAccountFor($stay);
            $clearing = static::clearingFor((int) $stay->store_id, $folio->currency);
            $woRef = 'hotel_folio:writeoff:'.$stay->id.':'.Str::uuid();
            app(Ledger::class)->post([
                ['account' => $folio, 'amount' => -$balance, 'ref' => $woRef, 'reason' => 'Folio write-off', 'granted_by' => $grantedBy],
                ['account' => $clearing, 'amount' => $balance, 'allow_negative' => true, 'ref' => $woRef.':clr', 'reason' => 'Folio write-off', 'granted_by' => $grantedBy],
            ], null, 'Folio write-off');
        }

        $stay->status = 'checked_out';
        $stay->checked_out_at = Carbon::now();
        $stay->save();

        // Mark the folio account settled/closed (non-financial status flag).
        if ($stay->folio_account_id) {
            LedgerAccount::whereKey($stay->folio_account_id)->update(['status' => 'settled']);
        }

        // HPMS A6 — sync the linked booking's lifecycle (checked_in → checked_out).
        if ($booking && $booking->status === HotelBooking::STATUS_CHECKED_IN) {
            $booking->status = HotelBooking::STATUS_CHECKED_OUT;
            $booking->save();
        }

        // HPMS A9 — housekeeping: a departure dirties the room and opens a clean task.
        HousekeepingService::onCheckOut($stay);

        return $stay->refresh();
    }

    /**
     * A11 — correct a stay's guest name/phone after check-in. guest_name is the ONLY
     * authorization for charge-to-room (guestNameMatches), so a front-desk typo
     * otherwise locks the real guest out permanently; this re-enables the match.
     */
    public static function updateGuest(HotelStay $stay, string $name, ?string $phone): HotelStay
    {
        $stay->guest_name = trim($name);
        $stay->guest_phone = ($phone !== null && trim($phone) !== '') ? trim($phone) : null;
        $stay->save();

        return $stay->refresh();
    }

    /**
     * A11 — cancel a mistaken stay (wrong room/guest) WITHOUT polluting checked-out
     * history. Allowed only at a ZERO folio balance (settle or write off first);
     * closes the folio account and frees the room for a new check-in (activeStayForRoom
     * keys on status='checked_in').
     */
    public static function cancelStay(HotelStay $stay): HotelStay
    {
        $stay->refresh();
        if ($stay->status !== 'checked_in') {
            throw new \RuntimeException('Only an active stay can be cancelled.');
        }
        if (static::balance($stay) > 0.001) {
            throw new \RuntimeException('This stay has an outstanding folio balance — settle or write it off before cancelling.');
        }

        $stay->status = 'cancelled';
        $stay->checked_out_at = Carbon::now(); // record-close time (used by hotel:prune-stays)
        $stay->save();

        if ($stay->folio_account_id) {
            LedgerAccount::whereKey($stay->folio_account_id)->update(['status' => 'settled']);
        }

        return $stay->refresh();
    }

    /**
     * A folio statement data structure for a stay: charges (room-service orders),
     * payments (settlements + write-offs), running totals and the balance. Reads
     * straight from the ledger entries so it is always authoritative.
     */
    public static function statement(HotelStay $stay): array
    {
        $folio = static::folioAccountFor($stay);
        $decimals = money_decimals($folio->currency); // A7: currency-aware rounding
        $entries = LedgerEntry::where('ledger_account_id', $folio->id)->orderBy('id')->get();

        $charges = [];
        $payments = [];
        $totalCharges = 0.0;
        $totalPayments = 0.0;

        foreach ($entries as $e) {
            $amt = (float) $e->amount;
            $row = [
                'id' => $e->id,
                'amount' => Money::round(abs($amt), $decimals),
                'reason' => $e->reason,
                'order_id' => $e->meta['order_id'] ?? null,
                'method' => $e->meta['method'] ?? null,
                'at' => $e->created_at,
            ];
            if ($amt >= 0) {
                $charges[] = $row;
                $totalCharges += $amt;
            } else {
                $payments[] = $row;
                $totalPayments += abs($amt);
            }
        }

        return [
            'stay_id' => $stay->id,
            'guest_name' => $stay->guest_name,
            'room' => $stay->table_id,
            'currency' => $folio->currency,
            'status' => $stay->status,
            'charges' => $charges,
            'payments' => $payments,
            'total_charges' => Money::round($totalCharges, $decimals),
            'total_payments' => Money::round($totalPayments, $decimals),
            'balance' => Money::round($totalCharges - $totalPayments, $decimals),
        ];
    }

    /**
     * HPMS A6 — post a stay's per-night room charges onto the folio (the night
     * audit, posted at check-in). One dated line per night, ref
     * `hotel_folio:night:{stay}:{Y-m-d}` — idempotent via the ledger's
     * (account, ref) unique, so re-posting (double check-in, extension replay) is
     * a no-op for already-posted nights. Description "Room {label} — {date}".
     *
     * @param  array<int, array{date: string, price: float}>  $nights
     */
    public static function postNightlyCharges(HotelStay $stay, array $nights, ?string $roomLabel = null): void
    {
        $stay->refresh();
        if (! $stay->isActive()) {
            throw new \RuntimeException('Cannot post room nights to a stay that is not checked in.');
        }

        $folio = static::folioAccountFor($stay);
        $clearing = static::clearingFor((int) $stay->store_id, $folio->currency);
        $decimals = money_decimals($folio->currency);
        $label = ($roomLabel !== null && trim($roomLabel) !== '') ? trim($roomLabel) : (string) $stay->table_id;

        foreach ($nights as $night) {
            $date = (string) $night['date'];
            $amount = Money::round((float) $night['price'], $decimals);
            if ($date === '' || $amount <= 0) {
                continue;
            }
            $ref = 'hotel_folio:night:'.$stay->id.':'.$date;
            $reason = 'Room '.$label.' — '.$date;

            app(Ledger::class)->post([
                ['account' => $clearing, 'amount' => -$amount, 'allow_negative' => true, 'ref' => $ref.':clr', 'reason' => $reason],
                ['account' => $folio, 'amount' => $amount, 'ref' => $ref.':folio', 'reason' => $reason,
                    'meta' => ['stay_id' => $stay->id, 'night' => $date, 'room' => $stay->table_id]],
            ], null, $reason);
        }
    }

    /**
     * HPMS A7 — post an extra-service charge (breakfast, parking, spa) onto a
     * stay's folio. charge_mode drives quantity expansion:
     *   once                 → qty units
     *   per_night            → qty × BOOKED nights (the linked booking's night
     *                          count; a walk-in stay with no booking → 1 night)
     *   per_person_per_night → qty × (adults+children of the linked booking, else
     *                          1) × booked nights
     * The service's tax_rate (a percent) is FOLDED into the line amount — there is
     * no separate tax line this run. Ref `hotel_svc:{stay}:{service}:{uuid}`: the
     * uuid is the caller's idempotency_key (a double-submit dedupes via the ledger
     * unique) or auto-generated (a legitimate repeat charge posts distinctly).
     *
     * @return array{tx: string, amount: float, units: float, ref: string}
     */
    public static function postServiceCharge(
        HotelStay $stay,
        HotelExtraService $service,
        float $qty = 1.0,
        ?string $idempotencyKey = null,
        ?int $grantedBy = null
    ): array {
        $stay->refresh();
        if (! $stay->isActive()) {
            throw new \RuntimeException('Cannot charge a service to a stay that is not checked in.');
        }

        $folio = static::folioAccountFor($stay);
        $clearing = static::clearingFor((int) $stay->store_id, $folio->currency);
        $decimals = money_decimals($folio->currency);

        // Booking context for per-night / per-person expansion.
        $booking = HotelBooking::where('hotel_stay_id', $stay->id)->first();
        $nights = $booking ? max(1, $booking->nightsCount()) : 1;
        $persons = $booking ? max(1, (int) $booking->adults + (int) $booking->children) : 1;
        $qty = max(0.0, $qty);

        $units = match ($service->charge_mode) {
            HotelExtraService::MODE_PER_NIGHT => $qty * $nights,
            HotelExtraService::MODE_PER_PERSON_PER_NIGHT => $qty * $persons * $nights,
            default => $qty, // once
        };

        // P1-05: exact decimal units × price × (1 + tax %), rounded once (HALF_UP, PHP-version independent).
        $withTax = Money::decimal($units)->multipliedBy(Money::decimal((float) $service->price))
            ->multipliedBy(Money::decimal((float) $service->tax_rate)->dividedBy(100, 20)->plus(1));
        $amount = Money::round((string) $withTax, $decimals);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Service charge amount must be positive.');
        }

        $uuid = $idempotencyKey ?: (string) Str::uuid();
        $ref = 'hotel_svc:'.$stay->id.':'.$service->id.':'.$uuid;
        $reason = trim((string) $service->name).' ×'.rtrim(rtrim(number_format($units, 2, '.', ''), '0'), '.');

        $tx = app(Ledger::class)->post([
            ['account' => $clearing, 'amount' => -$amount, 'allow_negative' => true, 'ref' => $ref.':clr', 'reason' => $reason, 'granted_by' => $grantedBy],
            ['account' => $folio, 'amount' => $amount, 'ref' => $ref.':folio', 'reason' => $reason, 'granted_by' => $grantedBy,
                'meta' => ['service_id' => $service->id, 'stay_id' => $stay->id, 'units' => $units, 'mode' => $service->charge_mode]],
        ], null, $reason);

        return ['tx' => $tx, 'amount' => $amount, 'units' => (float) $units, 'ref' => $ref];
    }

    /**
     * HPMS A6 — reverse the room-night charges the guest never stayed (an early
     * departure). Reverses every posted night on/after $departureDate that has not
     * already been reversed, keyed `hotel_folio:night:{stay}:{date}:rev`. The folio
     * leg is NOT allow_negative: if a settlement already consumed a night, its
     * reversal is skipped (left charged) rather than driving the folio negative.
     *
     * @return array<int, string> the night dates actually reversed
     */
    public static function reverseUnstayedNights(HotelStay $stay, string $departureDate): array
    {
        $folio = static::folioAccountFor($stay);
        $clearing = static::clearingFor((int) $stay->store_id, $folio->currency);
        $decimals = money_decimals($folio->currency);

        $charges = LedgerEntry::where('ledger_account_id', $folio->id)
            ->whereLike('ref', 'hotel_folio:night:'.$stay->id.':%:folio')
            ->get();

        $reversed = [];
        foreach ($charges as $charge) {
            // ref = hotel_folio:night:{stay}:{date}:folio
            $parts = explode(':', (string) $charge->ref);
            $date = $parts[3] ?? null;
            if (! $date || $date < $departureDate) {
                continue; // stayed night — keep the charge
            }
            $revRef = 'hotel_folio:night:'.$stay->id.':'.$date.':rev';
            if (LedgerEntry::where('ledger_account_id', $folio->id)->where('ref', $revRef.':folio')->exists()) {
                continue; // already reversed
            }
            $amount = Money::round(abs((float) $charge->amount), $decimals);
            if ($amount <= 0) {
                continue;
            }
            $reason = 'Unstayed night reversed — '.$date;
            try {
                app(Ledger::class)->post([
                    ['account' => $folio, 'amount' => -$amount, 'ref' => $revRef.':folio', 'reason' => $reason,
                        'meta' => ['stay_id' => $stay->id, 'night' => $date, 'reversal' => true]],
                    ['account' => $clearing, 'amount' => $amount, 'allow_negative' => true, 'ref' => $revRef.':clr', 'reason' => $reason],
                ], null, $reason);
                $reversed[] = $date;
            } catch (\RuntimeException $e) {
                // Balance guard rejected (a settlement already consumed this night) —
                // leave it charged rather than driving the folio negative.
            }
        }

        return $reversed;
    }

    // ── HPMS A8 — hotel events (banquets / venue hire) ───────────────────────

    /**
     * The standalone event's own ledger account (kind `hotel_event`), pinned via
     * `folio_account_id`. Only for events NOT linked to a stay (linked events bill
     * the stay folio). Currency fixed at open.
     */
    public static function eventAccountFor(HotelEvent $event): LedgerAccount
    {
        if ($event->folio_account_id) {
            $acct = LedgerAccount::find($event->folio_account_id);
            if ($acct) {
                return $acct;
            }
        }
        $acct = app(Ledger::class)->accountFor(
            'hotel_event',
            (int) $event->id,
            'hotel_event',
            static::currencyFor((int) $event->store_id)
        );
        if (! $event->folio_account_id) {
            $event->folio_account_id = $acct->id;
            $event->save();
        }

        return $acct;
    }

    /** The account an event's items bill to: the linked stay's folio, else its own event account. */
    protected static function eventTargetAccount(HotelEvent $event): LedgerAccount
    {
        if ($event->hotel_stay_id) {
            $stay = HotelStay::find($event->hotel_stay_id);
            if (! $stay) {
                throw new \RuntimeException('The stay linked to this event no longer exists.');
            }

            return static::folioAccountFor($stay);
        }

        return static::eventAccountFor($event);
    }

    /**
     * HPMS A8 — post an event's items as folio charges (idempotent per item, ref
     * `hotel_event:item:{event}:{item}`). Linked events bill the stay folio;
     * standalone events bill their own `hotel_event` account.
     *
     * @return array{account_id: int, total: float}
     */
    public static function postEventItems(HotelEvent $event): array
    {
        $target = static::eventTargetAccount($event);
        $clearing = static::clearingFor((int) $event->store_id, $target->currency);
        $decimals = money_decimals($target->currency);

        $total = 0.0;
        foreach ($event->items()->get() as $item) {
            $amount = Money::round((string) Money::decimal((float) $item->qty)->multipliedBy(Money::decimal((float) $item->unit_price)), $decimals);
            if ($amount <= 0) {
                continue;
            }
            $ref = 'hotel_event:item:'.$event->id.':'.$item->id;
            $reason = trim((string) $event->title).' — '.trim((string) $item->description);
            app(Ledger::class)->post([
                ['account' => $clearing, 'amount' => -$amount, 'allow_negative' => true, 'ref' => $ref.':clr', 'reason' => $reason],
                ['account' => $target, 'amount' => $amount, 'ref' => $ref.':folio', 'reason' => $reason,
                    'meta' => ['event_id' => $event->id, 'item_id' => $item->id]],
            ], null, $reason);
            $total += $amount;
        }

        return ['account_id' => (int) $target->id, 'total' => Money::round($total, $decimals)];
    }

    /**
     * HPMS A8 — reverse an event's posted item charges (used when a linked event is
     * cancelled). Balance-guarded; keyed `hotel_event:item:{event}:{item}:rev`.
     *
     * @return int the number of items reversed
     */
    public static function reverseEventItems(HotelEvent $event): int
    {
        $target = static::eventTargetAccount($event);
        $clearing = static::clearingFor((int) $event->store_id, $target->currency);
        $decimals = money_decimals($target->currency);

        $n = 0;
        foreach ($event->items()->get() as $item) {
            $charge = LedgerEntry::where('ledger_account_id', $target->id)
                ->where('ref', 'hotel_event:item:'.$event->id.':'.$item->id.':folio')->first();
            if (! $charge) {
                continue;
            }
            $revRef = 'hotel_event:item:'.$event->id.':'.$item->id.':rev';
            if (LedgerEntry::where('ledger_account_id', $target->id)->where('ref', $revRef.':folio')->exists()) {
                continue;
            }
            $amount = Money::round(abs((float) $charge->amount), $decimals);
            if ($amount <= 0) {
                continue;
            }
            $reason = 'Event cancelled — '.trim((string) $event->title);
            try {
                app(Ledger::class)->post([
                    ['account' => $target, 'amount' => -$amount, 'ref' => $revRef.':folio', 'reason' => $reason,
                        'meta' => ['event_id' => $event->id, 'item_id' => $item->id, 'reversal' => true]],
                    ['account' => $clearing, 'amount' => $amount, 'allow_negative' => true, 'ref' => $revRef.':clr', 'reason' => $reason],
                ], null, $reason);
                $n++;
            } catch (\RuntimeException $e) {
                // Already settled below this amount — leave it (owner voids via folio adjust).
            }
        }

        return $n;
    }

    /**
     * HPMS A8 — the outstanding balance of an event. Standalone: the event
     * account balance. Linked: this event's own net contribution to the stay folio
     * (its item charges minus any reversals).
     */
    public static function eventBalance(HotelEvent $event): float
    {
        $target = static::eventTargetAccount($event);
        $decimals = money_decimals($target->currency);

        if (! $event->hotel_stay_id) {
            return Money::round(app(Ledger::class)->accountBalance($target), $decimals);
        }

        // Linked: sum only this event's own refs on the stay folio.
        // P1-05: one account = one currency, so the integer minor SUM is exact.
        $sum = Money::majorFromMinor((int) LedgerEntry::where('ledger_account_id', $target->id)
            ->whereLike('ref', 'hotel_event:item:'.$event->id.':%:folio')
            ->sum('amount_minor'), $target->currency);

        return Money::round($sum, $decimals);
    }

    /**
     * HPMS A8 — settle a STANDALONE event's balance (linked events settle through
     * the stay folio). Mirrors folio settle; ref `hotel_event:settle:{uuid}`.
     */
    public static function settleEvent(HotelEvent $event, float $amount, string $method = 'cash', ?string $ref = null, ?int $grantedBy = null): string
    {
        if ($event->hotel_stay_id) {
            throw new \RuntimeException('A linked event is settled through the stay folio.');
        }
        $account = static::eventAccountFor($event);
        $clearing = static::clearingFor((int) $event->store_id, $account->currency);
        $amount = Money::round($amount, money_decimals($account->currency));
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Settlement amount must be positive.');
        }
        $ref = 'hotel_event:settle:'.($ref ?: (string) Str::uuid());
        $reason = 'Event settlement ('.$method.')';

        return app(Ledger::class)->post([
            ['account' => $account, 'amount' => -$amount, 'ref' => $ref.':folio', 'reason' => $reason,
                'granted_by' => $grantedBy, 'meta' => ['method' => $method, 'event_id' => $event->id]],
            ['account' => $clearing, 'amount' => $amount, 'allow_negative' => true, 'ref' => $ref.':clr', 'reason' => $reason, 'granted_by' => $grantedBy],
        ], null, $reason);
    }

    /**
     * HPMS A8 — an event statement mirroring {@see statement()}: charges, payments,
     * totals, balance. Reads the event account (standalone) or this event's own
     * refs on the linked stay folio.
     *
     * @return array{event_id: int, title: string, currency: string, status: string, charges: array<int, array<string, mixed>>, payments: array<int, array<string, mixed>>, total_charges: float, total_payments: float, balance: float}
     */
    public static function eventStatement(HotelEvent $event): array
    {
        $target = static::eventTargetAccount($event);
        $decimals = money_decimals($target->currency);

        $query = LedgerEntry::where('ledger_account_id', $target->id)->orderBy('id');
        if ($event->hotel_stay_id) {
            // Linked: restrict to this event's own item refs on the shared stay folio
            // (exact `hotel_event:item:{event}:…` so event 5 never matches event 15).
            $query->whereLike('ref', 'hotel_event:item:'.$event->id.':%');
        }
        $entries = $query->get();

        $charges = [];
        $payments = [];
        $totalCharges = 0.0;
        $totalPayments = 0.0;
        foreach ($entries as $e) {
            $amt = (float) $e->amount;
            $row = ['id' => $e->id, 'amount' => Money::round(abs($amt), $decimals), 'reason' => $e->reason, 'at' => $e->created_at];
            if ($amt >= 0) {
                $charges[] = $row;
                $totalCharges += $amt;
            } else {
                $payments[] = $row;
                $totalPayments += abs($amt);
            }
        }

        return [
            'event_id' => (int) $event->id,
            'title' => (string) $event->title,
            'currency' => $target->currency,
            'status' => (string) $event->status,
            'charges' => $charges,
            'payments' => $payments,
            'total_charges' => Money::round($totalCharges, $decimals),
            'total_payments' => Money::round($totalPayments, $decimals),
            'balance' => Money::round($totalCharges - $totalPayments, $decimals),
        ];
    }
}
