<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Payments;

use App\Models\Order;
use App\Services\KdsService;
use App\Services\OrderApiService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
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
        $transaction = $this->hosted->transactionFor($order);

        if ($order->hold_status === 'authorised' && $transaction !== null) {
            $hold = (int) $order->hold_minor;
            $amount = min($final, $hold); // never above what the shopper authorised; the store absorbs any excess
            if ($amount <= 0) {
                $result = $this->gateways->voidHold($transaction);
                if (! $result['ok']) {
                    throw new \RuntimeException((string) ($result['message'] ?? 'The card hold could not be released.'));
                }
                $order->forceFill(['hold_status' => 'voided', 'captured_minor' => 0])->save();

                return ['outcome' => 'voided', 'final_minor' => 0, 'released_minor' => $hold, 'refunded_minor' => 0];
            }
            // marked first: the settlement the capture triggers (paySuccess) must not announce the order again
            $order->forceFill(['hold_status' => 'captured', 'captured_minor' => $amount, 'captured_at' => now()])->save();
            $result = $this->gateways->captureHold($transaction, $amount);
            if (! $result['ok']) {
                $order->forceFill(['hold_status' => 'authorised', 'captured_minor' => null, 'captured_at' => null])->save();
                throw new \RuntimeException((string) ($result['message'] ?? 'The final amount could not be captured.'));
            }

            return ['outcome' => 'captured', 'final_minor' => $amount, 'released_minor' => $hold - $amount, 'refunded_minor' => 0];
        }

        if ($order->payment_mode === 'hosted' && (int) $order->is_paid === 1 && $transaction !== null) {
            // charged up front: the weighed difference only when the store refunds it (D7), removed lines always (API-31)
            $charged = Money::minorFromMajor((float) $transaction->amount, $currency) - $this->refundedMinor($order, $currency);
            $difference = max(0, $charged - $final);
            $refund = RefundSwitches::allows((int) $order->store_id, 'weighed_difference') || $final === 0
                ? $difference : min($difference, max(0, $removedMinor));
            if ($refund > 0) {
                $this->refund($order, $refund, 'weighed:'.$order->id, 'Final amount after picking');

                return ['outcome' => 'refunded', 'final_minor' => $final, 'released_minor' => 0, 'refunded_minor' => $refund];
            }

            return ['outcome' => 'none', 'final_minor' => $final, 'released_minor' => 0, 'refunded_minor' => 0];
        }

        return null; // pay later: the final amount is collected on delivery / collection
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
        $adjustment = OrderAdjustment::create([
            'order_id' => $order->id,
            'staff_id' => null,
            'pos_shift_id' => null,
            'type' => 'refund',
            'amount' => $amount,
            'method' => 'online',
            'reason' => $reason,
            'idempotency_key' => $key,
        ]);
        try {
            app(GeneralLedger::class)->postRefund($order, $amount, 'adj:'.$adjustment->id);
        } catch (\Throwable $e) {
            report($e);
        }
        log_activity('order.grocery_refund', $order, ['order_id' => $order->id, 'amount' => $amount, 'reason' => $reason], (int) $order->store_id);
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
