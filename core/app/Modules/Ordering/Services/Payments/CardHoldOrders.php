<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Payments;

use App\Models\Customer;
use App\Models\Order;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use App\Services\KdsService;
use App\Services\OrderApiService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
use Juwri\Core\Contracts\Marketplace\PlatformWalletTender;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\RefundSwitches;
use Juwri\Modules\Ordering\Contracts\DeliverySlotBook;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P3-09 (REVIEW, Opus: money) — the card hold of a weighed grocery basket (basket §9, owner-approved 2026-10-02; D7;
 * API-2, API-31, API-32):
 *
 *  - {@see authorised()}: the gateway authorised `hold_amount` (quote total + what the weighed lines may add). Inside
 *    the 15-minute window the order goes ahead for picking (out of `unpaid`, announced once, its slot kept); after it,
 *    the hold is a late payment for the owner to accept or release (API-21) — never revived silently.
 *  - {@see settleFinal()}: after picking, the final amount (Order::computeMoney with the weighed / replaced / removed
 *    lines) is captured — never above the authorised amount; nothing left (every line removed) voids the hold. A basket
 *    paid up front on a gateway without authorise/capture (only when the store refunds the weighed difference, D7) is
 *    refunded the difference; with the switch off only removed lines are refunded (API-31).
 *  - {@see refundRemovedLine()}: a line removed after the money was captured is always refunded (API-31), whatever
 *    the D7 switches say.
 *
 * Every step re-checks the order under its row lock, so a repeated call never captures or refunds twice.
 */
final class CardHoldOrders
{
    public function __construct(
        private readonly HostedOrderPayments $hosted,
        private readonly Gateways $gateways,
        private readonly DeliverySlotBook $slots,
    ) {}

    /** The gateway authorised the hold of this order transaction (Payments' OrderPaymentHandler::authorised). */
    public function authorised(Transaction $transaction): void
    {
        $order = Order::query()->where('store_id', $transaction->store_id)->whereKey($transaction->product_id)->first();
        if ($order === null) {
            return;
        }
        if ($order->payment_mode === 'hosted' && $order->status === 'unpaid') {
            $this->hosted->expire($order); // a window that ended makes it a late authorisation
            $order->refresh();
        }
        $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));
        $holdMinor = (int) $transaction->amount_minor;
        $expiresRaw = data_get($transaction->details, 'authorisation_expires_at');
        $expires = is_string($expiresRaw) && $expiresRaw !== '' ? CarbonImmutable::parse($expiresRaw) : null;

        $placed = DB::transaction(function () use ($order, $transaction, $holdMinor, $expires, $currency) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($locked === null || $locked->hold_status !== null) {
                return null; // recorded already
            }
            $fill = [
                'hold_status' => 'authorised',
                'hold_minor' => $holdMinor,
                'authorisation_expires_at' => $expires,
                'payment_gateway' => $transaction->transaction_gatway,
            ];
            if (LatePayments::isLate($locked)) {
                // API-21: the window ended first — the hold waits for the owner (accept or release), nothing placed
                $locked->forceFill($fill + [
                    'late_payment_status' => 'received_late',
                    'late_payment_minor' => $holdMinor,
                    'late_payment_at' => now(),
                ])->save();
                log_activity('order.late_payment_received', $locked, ['order_id' => $locked->id, 'amount' => Money::majorFromMinor($holdMinor, $currency), 'hold' => true], (int) $locked->store_id);

                return false;
            }
            if ($locked->status !== 'unpaid') {
                $locked->forceFill($fill)->save();

                return false;
            }
            $locked->forceFill($fill + ['status' => 'pending'])->save();

            return true;
        });
        if ($placed !== true) {
            return;
        }

        $this->slots->settleForOrder($order);
        try {
            KdsService::syncOrderStatus($order->id);
        } catch (\Throwable $e) {
            report($e);
        }
        $order->refresh();
        $owner = (int) (Store::query()->whereKey($order->store_id)->value('user_id') ?? 0);
        OrderApiService::announceCreated($owner, (int) $order->store_id, $order, (string) ($order->source ?: 'storefront_api'));
        log_activity('order.card_hold_authorised', $order, ['order_id' => $order->id, 'hold_minor' => $holdMinor], (int) $order->store_id);
    }

    /**
     * After picking: charge the final amount. Returns what happened: `captured` | `voided` | `refunded` | `none`, the
     * final amount, what was released or refunded (minor units), or null when the order has no online payment to settle.
     *
     * @return array{outcome: string, final_minor: int, released_minor: int, refunded_minor: int}|null
     */
    public function settleFinal(Order $order, int $removedMinor = 0): ?array
    {
        $order->refresh();
        $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));
        $order->unsetRelation('items');
        $final = Money::minorFromMajor((float) $order->computeMoney()['grand'], $currency);
        // every line removed: nothing is delivered, so nothing is charged (not even the delivery fee)
        $lines = $order->items()->get(['id', 'pick_status']);
        if ($lines->isNotEmpty() && $lines->every(fn ($line) => $line->pick_status === 'removed')) {
            $final = 0;
        }
        // grocery-snap2: the final amount is the whole order; the stored-value tenders (wallet, gift card, platform
        // wallet) already paid their part of it, so the card (hold or up-front charge) is settled for the rest only.
        // grocery-nits 4: tenders above the whole final amount paid for nothing; that part goes back once the card is settled
        $wholeFinal = $final;
        $final = max(0, $final - $this->tendersMinor($order, $currency));
        $transaction = $this->hosted->transactionFor($order);

        if ($order->hold_status === 'authorised' && $transaction !== null) {
            $hold = (int) $order->hold_minor;
            $amount = min($final, $hold); // never above what the shopper authorised; the store absorbs any excess
            if ($amount <= 0) {
                // grocery-nits 2: claimed atomically first (like voidOnCancel), so two racing settlements void once
                $claimed = Order::query()->whereKey($order->id)->where('hold_status', 'authorised')
                    ->update(['hold_status' => 'voided', 'captured_minor' => 0]);
                if ($claimed !== 1) {
                    throw new \RuntimeException('The card hold is already being settled.');
                }
                $order->refresh();
                $result = $this->gateways->voidHold($transaction);
                if (! $result['ok']) {
                    Order::query()->whereKey($order->id)->where('hold_status', 'voided')
                        ->update(['hold_status' => 'authorised', 'captured_minor' => null]);
                    $order->refresh();
                    throw new \RuntimeException((string) ($result['message'] ?? 'The card hold could not be released.'));
                }
                $this->returnTenderExcess($order, $wholeFinal, $currency);

                return ['outcome' => 'voided', 'final_minor' => 0, 'released_minor' => $hold, 'refunded_minor' => 0];
            }
            // marked first: the settlement the capture triggers (paySuccess) must not announce the order again.
            // grocery-snap2: claimed atomically, so a retried finish racing another one never captures twice.
            $claimed = Order::query()->whereKey($order->id)->where('hold_status', 'authorised')
                ->update(['hold_status' => 'captured', 'captured_minor' => $amount, 'captured_at' => now()]);
            if ($claimed !== 1) {
                throw new \RuntimeException('The card hold is already being settled.');
            }
            $order->refresh();
            $result = $this->gateways->captureHold($transaction, $amount);
            if (! $result['ok']) {
                Order::query()->whereKey($order->id)->where('hold_status', 'captured')
                    ->update(['hold_status' => 'authorised', 'captured_minor' => null, 'captured_at' => null]);
                $order->refresh();
                throw new \RuntimeException((string) ($result['message'] ?? 'The final amount could not be captured.'));
            }
            // grocery-nits2: a retry after an unknown outcome is the first request, with its amount: record what was taken
            $captured = (int) ($result['captured_minor'] ?? $amount);
            if ($captured !== $amount) {
                Order::query()->whereKey($order->id)->where('hold_status', 'captured')->update(['captured_minor' => $captured]);
                $order->refresh();
            }
            // money-tail 2: lines removed between the unknown outcome and the retry: the retry took the first, larger
            // amount; the part above the final amount goes back right away, once (keyed refund row)
            $drift = $captured > $amount ? $this->refundCaptureDrift($order, $captured - $amount, $currency) : 0;

            return ['outcome' => 'captured', 'final_minor' => $captured - $drift, 'released_minor' => $hold - $captured, 'refunded_minor' => $drift];
        }

        if ($order->payment_mode === 'hosted' && (int) $order->is_paid === 1 && $transaction !== null) {
            // charged up front: the weighed difference only when the store refunds it (D7), removed lines always (API-31)
            $charged = Money::minorFromMajor((float) $transaction->amount, $currency) - $this->refundedMinor($order, $currency);
            $difference = max(0, $charged - $final);
            $refund = RefundSwitches::allows((int) $order->store_id, 'weighed_difference') || $final === 0
                ? $difference : min($difference, max(0, $removedMinor));
            if ($refund > 0) {
                $this->refund($order, $refund, 'weighed:'.$order->id, 'Final amount after picking');
                $this->returnTenderExcess($order, $wholeFinal, $currency);

                return ['outcome' => 'refunded', 'final_minor' => $final, 'released_minor' => 0, 'refunded_minor' => $refund];
            }
            $this->returnTenderExcess($order, $wholeFinal, $currency);

            return ['outcome' => 'none', 'final_minor' => $final, 'released_minor' => 0, 'refunded_minor' => 0];
        }

        // pay later: the final amount is collected on delivery / collection (a tender above it still goes back)
        if ($order->payment_mode !== 'hosted') {
            $this->returnTenderExcess($order, $wholeFinal, $currency);
        }

        return null;
    }

    /**
     * grocery-nits 4 / grocery-nits2 (REVIEW, Opus: money): the stored-value tenders (wallet, gift card, platform
     * wallet) went above the final amount of the picked order (`$finalMinor`, the whole order): the extra goes back to
     * the tender that paid it (store-credit wallet first, then the gift card, then the platform wallet), in the order's
     * currency, like a cancel gives tenders back:
     *  - store credit: the ledger legs are posted directly (CustomerWalletService::returnTender), never through the
     *    issuance-gated credit, so it works on a store that cannot issue new store credit;
     *  - gift card: merchant → that card (GiftCardService::returnToCard), the merchant gives back the redeemed value;
     *  - platform wallet: through the edition's PlatformWalletTender (Community has none).
     * Each tender column is lowered by what went back, under the order lock and in the same transaction as the ledger
     * legs; each return's ref ends with the tender's new applied amount (minor units), which only ever goes down, so a
     * second return (a line removed after finishing) is posted under its own ref, never dropped as a duplicate, and:
     *  - it happens exactly once: a repeat sees tenders equal to the final amount;
     *  - a later cancel reverses only the tender that paid for goods, never the returned part a second time;
     *  - the refund caps (collected + tenders) shrink by the returned part, with no refund row to subtract twice.
     * A part that cannot go back (no customer account, no platform wallet) stays on the order and is logged for the
     * owner; a voided or expired gift card's part is paid out by GiftCardService (money-tail 3).
     */
    private function returnTenderExcess(Order $order, int $finalMinor, string $currency): void
    {
        $unreturned = [];
        try {
            $returned = DB::transaction(function () use ($order, $finalMinor, $currency, &$unreturned) {
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
                if ($locked === null) {
                    return 0;
                }
                $left = $this->tendersMinor($locked, $currency) - max(0, $finalMinor);
                if ($left <= 0) {
                    return 0; // nothing above the final amount, or returned already
                }
                $returned = 0;
                foreach (['wallet_applied', 'gift_card_applied', 'mk_wallet_applied'] as $tender) {
                    $applied = max(0, Money::minorFromMajor((float) ($locked->getAttribute($tender) ?? 0), $currency));
                    $part = min($left, $applied);
                    if ($part <= 0) {
                        continue;
                    }
                    $left -= $part; // a part that cannot go back stays on its tender (logged); the others return only their own part
                    $rest = $applied - $part;
                    if (! $this->returnTenderPart($locked, $tender, Money::majorFromMinor($part, $currency), $rest)) {
                        $unreturned[$tender] = Money::majorFromMinor($part, $currency);

                        continue;
                    }
                    $locked->setAttribute($tender, Money::majorFromMinor($rest, $currency));
                    $returned += $part;
                }
                $locked->save();

                return $returned;
            });
        } catch (\Throwable $e) {
            report($e);
            log_activity('order.tender_excess_unreturned', $order, ['order_id' => $order->id, 'reason' => $e->getMessage()], (int) $order->store_id);

            return;
        }
        if ($returned > 0) {
            $order->refresh();
            log_activity('order.tender_excess_returned', $order, ['order_id' => $order->id, 'amount' => Money::majorFromMinor($returned, $currency)], (int) $order->store_id);
        }
        if ($unreturned !== []) {
            log_activity('order.tender_excess_unreturned', $order, ['order_id' => $order->id, 'parts' => $unreturned], (int) $order->store_id);
        }
    }

    /** One tender's part of the excess goes back to that tender; `$restMinor` (its new applied amount) keys the ref. */
    private function returnTenderPart(Order $locked, string $tender, float $amount, int $restMinor): bool
    {
        $orderId = (int) $locked->id;
        if ($tender === 'wallet_applied') {
            $customer = (int) ($locked->customer_id ?? 0) > 0 ? Customer::query()->find((int) $locked->customer_id) : null;
            if ($customer === null) {
                return false;
            }
            CustomerWalletService::returnTender($customer, $amount, $orderId, 'tender-excess:order:'.$orderId.':'.$restMinor);

            return true;
        }
        if ($tender === 'gift_card_applied') {
            return GiftCardService::returnToCard($locked, $amount, 'gc:excess:order:'.$orderId.':card:'.(int) $locked->gift_card_id.':'.$restMinor);
        }

        return app(PlatformWalletTender::class)->returnPart($locked, $amount, 'tender-excess:'.$restMinor);
    }

    /**
     * grocery-nits2 (REVIEW, Opus: money): an admin resolves a card-hold capture stuck in `capturing` (the provider's
     * answer was lost and the gateway cannot retry safely) after checking the payment with the provider. Captured:
     * the order is marked captured for the stored request's amount first (so the settlement it triggers never
     * announces the order again), then the transaction is recorded and settled; a refused resolve rolls the order
     * back. Not captured: the transaction is `authorised` again and the next finish captures with a new request.
     * Audited (`payment.capture_resolved`, and the check is kept on the transaction).
     *
     * @return array{ok: bool, message: string|null}
     */
    public function resolveStuckCapture(Transaction $transaction, bool $captured, ?string $reference, string $checkedBy): array
    {
        $transaction->refresh();
        if ((string) $transaction->status !== 'capturing') {
            return ['ok' => false, 'message' => 'This payment is not waiting for a capture check.'];
        }
        $order = Order::query()->where('store_id', $transaction->store_id)->whereKey($transaction->product_id)->first();
        if ($order === null || $this->hosted->transactionFor($order)?->getKey() !== $transaction->getKey()) {
            return ['ok' => false, 'message' => 'No order card hold belongs to this payment.'];
        }
        $minor = (int) data_get($transaction->details, 'capture_pending.minor', 0);
        $audit = ['by' => $checkedBy, 'at' => now()->toIso8601String(), 'reference' => $reference];
        if ($captured) {
            $claimed = Order::query()->whereKey($order->id)->where('hold_status', 'authorised')
                ->update(['hold_status' => 'captured', 'captured_minor' => $minor, 'captured_at' => now()]);
            if ($claimed !== 1) {
                return ['ok' => false, 'message' => 'The order\'s card hold is not open.'];
            }
            $result = $this->gateways->resolveCapture($transaction, true, $reference, $audit);
            if (! $result['ok']) {
                Order::query()->whereKey($order->id)->where('hold_status', 'captured')
                    ->update(['hold_status' => 'authorised', 'captured_minor' => null, 'captured_at' => null]);

                return ['ok' => false, 'message' => $result['message']];
            }
        } else {
            $result = $this->gateways->resolveCapture($transaction, false, null, $audit);
            if (! $result['ok']) {
                return ['ok' => false, 'message' => $result['message']];
            }
        }
        log_activity('payment.capture_resolved', $order, [
            'order_id' => $order->id, 'transaction_id' => $transaction->id, 'captured' => $captured,
            'amount_minor' => $captured ? $minor : 0, 'reference' => $reference, 'by' => $checkedBy,
        ], (int) $order->store_id);

        return ['ok' => true, 'message' => null];
    }

    /** A cancelled order's authorised hold is released (nothing captured). Returns whether a hold was voided. */
    public function voidOnCancel(Order $order): bool
    {
        $claimed = Order::query()->whereKey($order->id)->where('hold_status', 'authorised')->update(['hold_status' => 'voided', 'captured_minor' => 0]);
        if ($claimed !== 1) {
            return false;
        }
        $transaction = $this->hosted->transactionFor($order);
        if ($transaction === null) {
            return true;
        }
        $result = $this->gateways->voidHold($transaction);
        if (! $result['ok']) {
            report(new \RuntimeException('Card hold void failed for order '.$order->id.': '.(string) ($result['message'] ?? '')));
        }
        log_activity('order.card_hold_voided', $order, ['order_id' => $order->id], (int) $order->store_id);

        return true;
    }

    /**
     * API-31: refund a line removed after the money was captured (or charged), whatever the D7 switches say. Returns
     * the refunded amount (minor units), 0 when the order holds no captured online money.
     */
    public function refundRemovedLine(Order $order, int $lineMinor, string $key): int
    {
        $order->refresh();
        if ($lineMinor <= 0 || $order->payment_mode !== 'hosted' || (int) $order->is_paid !== 1) {
            return 0;
        }
        $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));
        $transaction = $this->hosted->transactionFor($order);
        if ($transaction === null) {
            return 0;
        }
        $left = Money::minorFromMajor((float) $transaction->amount, $currency) - $this->refundedMinor($order, $currency);
        $amount = min($lineMinor, max(0, $left));
        if ($amount > 0) {
            $this->refund($order, $amount, $key, 'Removed line after capture');
        }

        return $amount;
    }

    /**
     * grocery-snap2: what the card (or other gateway tender) collected for a picked grocery order, minor units, before
     * any refund row; null when the order is not a picked order with an online payment. Picking re-snapshots the stored
     * totals to the picked lines, but the money collected is the charge itself: the up-front charge (the refunds picking
     * made are refund rows, subtracted once by the refund caps; a difference the store kept stays refundable), or the
     * captured part of a settled card hold (0 once voided, 0 while a failed capture leaves the hold uncaptured).
     */
    public function collectedMinor(Order $order): ?int
    {
        if ($order->getAttribute('picking_status') !== 'picked') {
            return null;
        }
        // grocery-nits 1: only money that was actually taken, the same orders settleFinal settles (a hold, or paid up front)
        if ($order->getAttribute('hold_status') === null && ! ($order->payment_mode === 'hosted' && (int) $order->is_paid === 1)) {
            return null;
        }
        $transaction = $this->hosted->transactionFor($order);
        if ($transaction === null) {
            return null;
        }
        $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));
        $charged = max(0, Money::minorFromMajor((float) $transaction->amount, $currency));
        $hold = (string) $order->getAttribute('hold_status');
        if ($hold === 'authorised') {
            return 0;
        }
        if (in_array($hold, ['captured', 'voided'], true)) {
            return min($charged, max(0, (int) $order->getAttribute('captured_minor')));
        }

        return $charged;
    }

    /** The stored-value tenders of the order (wallet, gift card, platform wallet), minor units. */
    private function tendersMinor(Order $order, string $currency): int
    {
        $sum = 0;
        foreach (['wallet_applied', 'gift_card_applied', 'mk_wallet_applied'] as $tender) {
            $sum += Money::minorFromMajor((float) ($order->getAttribute($tender) ?? 0), $currency);
        }

        return max(0, $sum);
    }

    private function refund(Order $order, int $minor, string $key, string $reason): void
    {
        $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));
        if (OrderAdjustment::query()->where('order_id', $order->id)->where('idempotency_key', $key)->exists()) {
            return;
        }
        $amount = Money::majorFromMinor($minor, $currency);
        $result = $this->gateways->refundOrder($order, $amount);
        if (! empty($result['refused'])) {
            throw new \RuntimeException((string) ($result['message'] ?? 'The refund was refused.'));
        }
        $row = [
            'order_id' => $order->id, 'staff_id' => null, 'pos_shift_id' => null, 'type' => 'refund',
            'amount' => $amount, 'method' => 'online', 'reason' => $reason, 'idempotency_key' => $key,
        ];
        try {
            try {
                $adjustment = OrderAdjustment::create($row);
            } catch (\Throwable $first) {
                // ML-1120 review NIT 3: the refund row is what every refund cap subtracts (the store-credit cap does not
                // read the provider ledger), so a failed write is tried once more, in its own try, before it is only
                // logged; a row that did land (same key) counts as written.
                report($first);
                $adjustment = OrderAdjustment::query()->where('order_id', $order->id)->where('idempotency_key', $key)->first()
                    ?? OrderAdjustment::create($row);
            }
        } catch (\Throwable $e) {
            // money-last 3 (REVIEW, Opus: money): the provider refunded but the refund row was not saved. The money moved,
            // so this is not a failed refund: the owner is told it is unrecorded, with the provider reference, so nobody
            // pays it again by hand. The provider's own refund ledger (RefundGuard) still counts it against the cap.
            report($e);
            log_activity('order.refund_unrecorded', $order, [
                'order_id' => $order->id, 'amount' => $amount, 'key' => $key, 'reason' => $reason,
                'gateway' => $result['gateway'] ?? null, 'reference' => $result['reference'] ?? null,
                'automatic' => $result['automatic'] ?? null, 'error' => $e->getMessage(),
            ], (int) $order->store_id);

            return;
        }
        try {
            app(GeneralLedger::class)->postRefund($order, $amount, 'adj:'.$adjustment->id);
        } catch (\Throwable $e) {
            report($e);
        }
        log_activity('order.grocery_refund', $order, ['order_id' => $order->id, 'amount' => $amount, 'reason' => $reason], (int) $order->store_id);
    }

    /**
     * money-tail 2 (REVIEW, Opus: money): a capture retried after an unknown outcome repeats the first request, so it
     * can take more than the final amount when lines were removed in between. The difference is refunded at once under
     * `capture-drift:{order}` (one refund row per order, so never twice). A refund that fails is logged for the owner
     * (`order.capture_above_final`) and the capture itself still stands. Returns what was refunded (minor units).
     */
    private function refundCaptureDrift(Order $order, int $driftMinor, string $currency): int
    {
        try {
            $this->refund($order, $driftMinor, 'capture-drift:'.$order->id, 'Captured above the final amount');

            return $driftMinor;
        } catch (\Throwable $e) {
            report($e);
            log_activity('order.capture_above_final', $order, [
                'order_id' => $order->id, 'amount' => Money::majorFromMinor($driftMinor, $currency), 'reason' => $e->getMessage(),
            ], (int) $order->store_id);

            return 0;
        }
    }

    private function refundedMinor(Order $order, string $currency): int
    {
        $sum = 0;
        foreach (OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'refund')->get() as $row) {
            $sum += Money::minorFromMajor((float) $row->amount, $currency);
        }

        return $sum;
    }
}
