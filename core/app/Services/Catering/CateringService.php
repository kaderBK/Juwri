<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Catering;

use App\Models\CateringRequest;
use App\Models\Order;
use App\Services\FeatureAccess;
use App\Services\OrderApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * GAPS-RUN-2 B8-P1: catering lifecycle. request → quote → accept (→ scheduled Order,
 * optional deposit) → decline. Server-side lead-time + minimum-spend gates. The
 * accepted order rides the existing scheduling pipeline (scheduled_at = event_at);
 * the deposit mirrors ReservationDepositService (ledger hold, idempotent).
 */
class CateringService
{
    /** Create a catering request after validating lead time + headcount. */
    public function createRequest(int $storeId, array $data): CateringRequest
    {
        /* FIX-2 F2-6 (gap audit): this is the PUBLIC side door
           (`publicView.catering.request`) — an anonymous shopper POSTs here, so
           the entitlement subject is the STORE OWNER. Kill veto + plan_gated
           entitlement, same contract as the `feature:catering` middleware on
           the owner dashboard group. */
        if (! FeatureAccess::storeAllows($storeId, 'catering') || ! store_options($storeId, 'catering_enabled')) {
            throw ValidationException::withMessages(['catering' => 'Catering is not available.']);
        }

        $eventAt = Carbon::parse($data['event_at']);
        $leadDays = max(0, (int) (store_options($storeId, 'catering_min_lead_days') ?: 0));
        if ($eventAt->lt(now()->addDays($leadDays)->startOfDay())) {
            throw ValidationException::withMessages([
                'event_at' => "Catering needs at least {$leadDays} day(s) notice.",
            ]);
        }

        $headcount = max(1, (int) ($data['headcount'] ?? 1));

        return CateringRequest::create([
            'store_id' => $storeId,
            'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'event_at' => $eventAt,
            'headcount' => $headcount,
            'message' => $data['message'] ?? null,
            'items' => $data['items'] ?? null,
            'status' => 'requested',
        ]);
    }

    /** Owner sets a quote. */
    public function quote(CateringRequest $request, float $total, ?string $note = null): CateringRequest
    {
        $request->update([
            'quote_total' => Money::round($total, Currencies::exponent($request->minorMoneyCurrency())), // bug batch 4 · 1
            'quote_note' => $note,
            'status' => 'quoted',
        ]);

        return $request;
    }

    /**
     * Accept a quoted request → create the scheduled catering Order. Enforces the
     * server-side minimum spend. $lineItems is OrderApiService::create's items array.
     */
    public function accept(CateringRequest $request, array $lineItems): Order
    {
        $rid = (int) $request->store_id;

        /* FIX-2 F2-6: accept() mints a real Order (and optionally a deposit
           charge), so it re-asserts the store's entitlement rather than
           trusting the route middleware alone. */
        if (! FeatureAccess::storeAllows($rid, 'catering')) {
            throw ValidationException::withMessages(['catering' => 'Catering is not available.']);
        }

        $minSpend = (float) (store_options($rid, 'catering_min_spend') ?: 0);
        $quote = (float) ($request->quote_total ?? 0);
        if ($minSpend > 0 && $quote < $minSpend) {
            throw ValidationException::withMessages([
                'quote_total' => "Catering minimum spend is {$minSpend}.",
            ]);
        }

        $ownerId = (int) Store::where('id', $rid)->value('user_id');
        // Catering events can be weeks out — beyond the normal scheduling horizon.
        // scheduled_at_trusted sets the event time INSIDE create(), before the KDS
        // sync / staff push, so the order stays pending-schedule and doesn't fire the
        // kitchen the moment it's accepted.
        $order = OrderApiService::create($ownerId, $rid, [
            'type' => 'takeaway',
            'items' => $lineItems,
            'customer_name' => $request->name,
            'phone' => $request->phone,
            'branch_id' => $request->branch_id,
            'source' => 'catering',
            'note' => $request->message,
            'scheduled_at_trusted' => $request->event_at->toDateTimeString(),
        ]);
        $order->is_catering = true;
        $order->save();

        $request->update(['status' => 'accepted', 'order_id' => $order->id]);

        // Arm the async deposit pay-link when the store takes a catering deposit. The
        // customer isn't in-session at owner-accept, so payment can't be a synchronous
        // gateway redirect — stamp a capability token + the due amount and mark it
        // pending; the customer pays later via {slug}/catering/deposit/{token}, which
        // opens the charge (initiateDeposit) and captures on confirmation
        // (markDepositPaid). No-op unless a deposit is actually due.
        $this->armDeposit($request);

        return $order;
    }

    /**
     * Arm the async deposit pay-link for an accepted request. Stamps a capability token +
     * the due amount and marks the deposit pending. No-op when deposits are off, no amount
     * is set, or a deposit is already armed/paid. Returns the (possibly updated) request.
     */
    public function armDeposit(CateringRequest $request): CateringRequest
    {
        $rid = (int) $request->store_id;
        if (! $this->depositEnabled($rid) || ! empty($request->deposit_status)) {
            return $request;
        }
        $amount = Money::round((float) (store_options($rid, 'catering_deposit_amount') ?: 0), Currencies::exponent((string) (store_options($rid, 'currency_code') ?: app(Ledger::class)->defaultCurrency())));
        if ($amount <= 0) {
            return $request;
        }
        $request->update([
            'token' => $request->token ?: Str::random(40),
            'deposit_amount' => $amount,
            'deposit_status' => 'pending',
            'currency' => (string) (store_options($rid, 'currency_code') ?: app(Ledger::class)->defaultCurrency()),
        ]);

        return $request;
    }

    public function decline(CateringRequest $request): CateringRequest
    {
        $request->update(['status' => 'declined']);

        return $request;
    }

    // --- Deposit (mirror of ReservationDepositService) ---------------------

    public function depositEnabled(int $storeId): bool
    {
        return ! empty(store_options($storeId, 'catering_deposit_enabled'));
    }

    public function initiateDeposit(CateringRequest $request, string $gatewayFolder): ?Transaction
    {
        $rid = (int) $request->store_id;
        if (! $this->depositEnabled($rid)) {
            return null;
        }
        $amount = Money::round((float) (store_options($rid, 'catering_deposit_amount') ?: 0), Currencies::exponent((string) (store_options($rid, 'currency_code') ?: app(Ledger::class)->defaultCurrency())));
        if ($amount <= 0) {
            return null;
        }
        $currency = (string) (store_options($rid, 'currency_code') ?: app(Ledger::class)->defaultCurrency());
        $ownerId = (int) Store::where('id', $rid)->value('user_id');

        $transaction = Transaction::create([
            'product_name' => __('Catering deposit'),
            'product_id' => 0,
            'user_id' => $ownerId,
            'base_amount' => $amount,
            'amount' => $amount,
            'currency_code' => $currency,
            'transaction_method' => 'catering_deposit',
            'store_id' => $rid, // P1-23: charged on the store's own gateway
            'transaction_gatway' => $gatewayFolder,
            'transaction_description' => __('Catering deposit').' #'.$request->id,
            'frequency' => 'LIFETIME',
            'status' => null,
        ]);

        $request->update([
            'deposit_amount' => $amount,
            'deposit_status' => 'pending',
            'deposit_transaction_id' => $transaction->id,
            'currency' => $currency,
        ]);

        return $transaction;
    }

    /** Confirmed deposit → ledger hold. Idempotent. */
    public function markDepositPaid(CateringRequest $request, Transaction $transaction): void
    {
        DB::transaction(function () use ($request, $transaction) {
            $request = CateringRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->deposit_status === 'paid') {
                return; // idempotent
            }
            $ownerId = (int) Store::where('id', $request->store_id)->value('user_id');
            $currency = $request->currency ?: (string) (store_options((int) $request->store_id, 'currency_code') ?: app(Ledger::class)->defaultCurrency());
            $merchant = app(Ledger::class)->accountFor('merchant', $ownerId, 'merchant', $currency);
            $clearing = app(Ledger::class)->accountFor('platform', 0, 'platform', $currency);
            app(Ledger::class)->post([
                ['account' => $clearing, 'amount' => -(float) $request->deposit_amount, 'allow_negative' => true, 'ref' => 'catdep:pay:'.$transaction->id.':clr'],
                ['account' => $merchant, 'amount' => (float) $request->deposit_amount, 'ref' => 'catdep:pay:'.$transaction->id.':mer'],
            ], null, 'catering_deposit.pay');

            $request->update(['deposit_status' => 'paid']);
        });
    }
}
