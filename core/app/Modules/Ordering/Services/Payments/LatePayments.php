<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Payments;

use App\Models\Order;
use App\Services\InventoryService;
use App\Services\KdsService;
use App\Services\Loyalty\LoyaltyAccrual;
use App\Services\OrderApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Contracts\DeliverySlotBook;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Services\OrderPaidSideEffects;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Contracts\Outbox;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P3-06b (REVIEW, Opus: payments) — late payments of headless hosted orders (kit/api README §2.12, API-21, API-27,
 * API-28, API-32). Owner decisions:
 *
 *  - API-21 {@see receive()}: a payment the gateway confirms after the 15-minute window does NOT revive the order and
 *    is NOT refunded automatically. The order stays `cancelled` (slot and stock stay released), the payment reads
 *    `received_late` with the amount received, and the store dashboard lists it for the owner to decide.
 *  - API-27 {@see accept()}: accepting re-reserves the stock (and the scheduled slot) when they are still free: the
 *    order is placed (paid) and the kitchen gets it. When they are not, the money is accepted and the order waits as
 *    `pending_approval` (the existing approvals queue) for the owner to pick another time or adjust it, or refund.
 *    Same record, number and tracking link in every outcome.
 *  - API-28 {@see refund()}: refunding a late payment is always allowed — no per-flow refund switch applies, because
 *    the order never went ahead. Through the store's gateway refund (GatewayRefundService: automatic when the store
 *    turned automatic refunds on, else a tracked manual refund), with a refund row and the GL post like any refund.
 *  - API-32 {@see expireAuthorisations()}: an accepted late card hold still `pending_approval` when its gateway
 *    authorisation lapses (`authorisation_expires_at`) is cancelled automatically and the hold reads `voided`
 *    (nothing charged). Card holds arrive with weighed baskets (P3-09); this already handles any row that has one.
 *
 * The paid-order side effects (accounting sync, POS push, GL post, `order.paid` webhook, loyalty) run only when the
 * owner accepts — never for a payment the store may still give back.
 */
final class LatePayments
{
    public function __construct(
        private readonly SchedulingService $scheduling,
    ) {}

    /**
     * Is this an order closed before the gateway confirmed its payment — a hosted order whose window ended, or
     * (P6-254) any online order cancelled, rejected or refunded while its pay page was still open?
     */
    public static function isLate(?Order $order): bool
    {
        return $order !== null && self::closed($order)
            && (int) $order->is_paid !== 1 && $order->late_payment_status === null;
    }

    /** P6-254: the order can no longer go ahead on its own (cancelled, rejected or refunded). */
    private static function closed(Order $order): bool
    {
        return in_array((string) $order->status, PaymentController::CLOSED_ORDER_STATUSES, true);
    }

    /** API-21: record the late payment on the cancelled order (called by PaymentController::paySuccess). */
    public function receive(Order $order, Transaction $transaction): void
    {
        DB::transaction(function () use ($order, $transaction) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! self::isLate($locked)) {
                return;
            }
            $currency = strtoupper((string) ($locked->currency_code ?: store_currency((int) $locked->store_id)));
            $locked->forceFill([
                'late_payment_status' => 'received_late',
                'late_payment_minor' => Money::minorFromMajor((float) $transaction->amount, $currency),
                'late_payment_at' => now(),
                'payment_gateway' => $transaction->transaction_gatway,
            ])->save();
            log_activity('order.late_payment_received', $locked, [
                'order_id' => $locked->id, 'amount' => (float) $transaction->amount, 'gateway' => $transaction->transaction_gatway,
            ], (int) $locked->store_id);
        });
        $order->refresh();
    }

    /**
     * API-27: accept a late payment. Returns `placed` when the stock and slot were re-reserved, `pending_approval`
     * when they were not (money accepted, owner picks another time or adjusts), or null when there is nothing to
     * accept (not a late payment waiting for a decision).
     */
    public function accept(Order $order): ?string
    {
        /** @var array{0: string, 1: Order, 2: bool}|null $result */
        $result = DB::transaction(function () use ($order) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($locked === null || $locked->late_payment_status !== 'received_late' || ! self::closed($locked)) {
                return null;
            }
            $locked->loadMissing('items');
            // money-last 1 (REVIEW, Opus: money): the expiry cancel gave the stored-value tenders back (gift card, store
            // credit, platform wallet), so the late gateway payment no longer covers the order. Never re-apply them here
            // (the balance may be spent by now): the order waits as `pending_approval` for the owner, who is told the gap.
            $currency = strtoupper((string) ($locked->currency_code ?: store_currency((int) $locked->store_id)));
            $shortMinor = Money::minorFromMajor((float) $locked->amount_due, $currency) - (int) $locked->late_payment_minor;
            if ($shortMinor > 0) {
                log_activity('order.late_tenders_returned', $locked, [
                    'order_id' => $locked->id, 'short' => Money::majorFromMinor($shortMinor, $currency),
                    'received' => Money::majorFromMinor((int) $locked->late_payment_minor, $currency),
                    'message' => ___('This late payment does not cover the order: its gift card, store credit or wallet part was given back when the order expired. Collect :amount or refund the payment.', ['amount' => Money::majorFromMinor($shortMinor, $currency)]),
                ], (int) $locked->store_id);
            }
            $free = $shortMinor <= 0 && $this->slotFree($locked) && $this->stockFree($locked);
            // P3-09 (API-27): a grocery order's delivery / collection slot is taken again (a seat, under the capacity guard)
            $free = $free && app(DeliverySlotBook::class)->retakeForOrder($locked);
            // P6-103: stockFree() is only a read — a shopper can take the last unit between it and here. The
            // re-reservation is the conditional take (never negative); when it loses, the order waits for the owner.
            $free = $free && $this->reserveStock($locked);
            // P3-09: an authorised card hold is not money taken — it is captured after picking (CardHoldOrders)
            $hold = $locked->getAttribute('hold_status') === 'authorised';
            $locked->forceFill([
                'is_paid' => $hold ? 0 : 1,
                'late_payment_status' => 'accepted',
                'status' => $free ? 'pending' : Order::STATUS_PENDING_APPROVAL,
                'cancelled_at' => null,
            ])->save();
            log_activity('order.late_payment_accepted', $locked, ['order_id' => $locked->id, 'reserved' => $free], (int) $locked->store_id);

            return [$free ? 'placed' : 'pending_approval', $locked, $hold];
        });
        if ($result === null) {
            return null;
        }
        [$outcome, $accepted, $hold] = $result;

        // The order now goes ahead (or waits for the owner with the money accepted): the paid-order side effects run
        // once, exactly as paySuccess would have run them on time — for a card hold, when it is captured.
        $transaction = $hold ? null : app(HostedOrderPayments::class)->transactionFor($accepted);
        if ($transaction !== null) {
            $outbox = app(Outbox::class);
            $ids = DB::transaction(fn () => OrderPaidSideEffects::record($outbox, $transaction));
            $outbox->relayAfterCommit($ids);
        }
        if (! $hold) {
            LoyaltyAccrual::earnForOrder($accepted);
        }
        if ($outcome === 'placed') {
            try {
                KdsService::syncOrderStatus($accepted->id);
            } catch (\Throwable $e) {
                report($e);
            }
            $accepted->refresh();
            $owner = (int) (Store::query()->whereKey($accepted->store_id)->value('user_id') ?? 0);
            OrderApiService::announceCreated($owner, (int) $accepted->store_id, $accepted, (string) ($accepted->source ?: 'storefront_api'));
        }
        $order->refresh();

        return $outcome;
    }

    /**
     * API-28: refund a late payment, from `received_late` or from an accepted late payment still waiting as
     * `pending_approval`. The order ends `cancelled` with the payment `refunded` (or `voided` for an authorised card
     * hold). Returns the gateway result (`automatic`, `gateway`, `reference`, `message`) or null when there is
     * nothing to refund; throws nothing on a provider refusal — the result says `refused`.
     *
     * @return array<string, mixed>|null
     */
    public function refund(Order $order): ?array
    {
        $outcome = DB::transaction(function () use ($order) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }
            $waiting = $locked->late_payment_status === 'received_late' && self::closed($locked);
            $acceptedPending = $locked->late_payment_status === 'accepted' && $locked->status === Order::STATUS_PENDING_APPROVAL;
            if (! $waiting && ! $acceptedPending) {
                return null;
            }
            $currency = strtoupper((string) ($locked->currency_code ?: store_currency((int) $locked->store_id)));
            $amount = Money::majorFromMinor((int) $locked->late_payment_minor, $currency);
            if ($amount <= 0) {
                return null;
            }
            if ($locked->getAttribute('hold_status') === 'authorised') {
                // P3-09 (API-28): a late card hold is released, not refunded — nothing was charged
                $transaction = app(HostedOrderPayments::class)->transactionFor($locked);
                $void = $transaction !== null ? app(Gateways::class)->voidHold($transaction) : ['ok' => true, 'reference' => null, 'message' => null];
                if (! $void['ok']) {
                    return ['result' => ['refused' => true, 'message' => $void['message']], 'adjustment' => null, 'order' => $locked];
                }
                $locked->forceFill([
                    'late_payment_status' => 'voided',
                    'hold_status' => 'voided',
                    'captured_minor' => 0,
                    'status' => 'cancelled',
                    'cancelled_at' => $locked->cancelled_at ?? now(),
                    'is_paid' => 0,
                ])->save();
                log_activity('order.late_hold_released', $locked, ['order_id' => $locked->id], (int) $locked->store_id);

                return ['result' => ['automatic' => true, 'voided' => true, 'reference' => $void['reference']], 'adjustment' => null, 'order' => $locked];
            }

            $result = app(Gateways::class)->refundOrder($locked, $amount);
            if (! empty($result['refused'])) {
                return ['result' => $result, 'adjustment' => null, 'order' => $locked];
            }
            $adjustment = OrderAdjustment::create([
                'order_id' => $locked->id,
                'staff_id' => null,
                'pos_shift_id' => null,
                'type' => 'refund',
                'amount' => $amount,
                'method' => 'online',
                'reason' => 'Late payment refund',
                'idempotency_key' => 'late-payment:'.$locked->id,
            ]);
            $locked->forceFill([
                'late_payment_status' => 'refunded',
                'status' => 'cancelled',
                'cancelled_at' => $locked->cancelled_at ?? now(),
                'is_paid' => 0,
                'refund_request_status' => 'approved',
                'refund_method' => 'original_payment',
            ])->save();

            return ['result' => $result, 'adjustment' => $adjustment, 'order' => $locked, 'amount' => $amount];
        });
        if ($outcome === null) {
            return null;
        }
        if ($outcome['adjustment'] !== null) {
            try {
                app(GeneralLedger::class)->postRefund($outcome['order'], (float) $outcome['amount'], 'adj:'.$outcome['adjustment']->id);
            } catch (\Throwable $e) {
                report($e);
            }
            log_activity('order.late_payment_refunded', $outcome['order'], [
                'order_id' => $outcome['order']->id,
                'amount' => $outcome['amount'],
                'gateway' => $outcome['result']['gateway'] ?? null,
                'automatic' => $outcome['result']['automatic'] ?? false,
            ], (int) $outcome['order']->store_id);
        }
        $order->refresh();

        return $outcome['result'];
    }

    /** API-32: cancel accepted late card holds that lapsed while waiting as `pending_approval` (all stores). */
    public function expireAuthorisations(): int
    {
        $count = 0;
        TenantContext::bypass(function () use (&$count) {
            Order::query()
                ->where('status', Order::STATUS_PENDING_APPROVAL)
                ->where('late_payment_status', 'accepted')
                ->whereNotNull('authorisation_expires_at')
                ->where('authorisation_expires_at', '<=', now())
                ->orderBy('id')
                ->chunkById(200, function ($orders) use (&$count) {
                    foreach ($orders as $order) {
                        $voided = DB::transaction(function () use ($order) {
                            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
                            if ($locked === null || $locked->status !== Order::STATUS_PENDING_APPROVAL || $locked->late_payment_status !== 'accepted'
                                || $locked->authorisation_expires_at === null || $locked->authorisation_expires_at->isFuture()) {
                                return false;
                            }
                            // the authorisation lapsed at the gateway: nothing to void there (P3-09 hold_status follows)
                            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'is_paid' => 0, 'late_payment_status' => 'voided']
                                + ($locked->getAttribute('hold_status') === 'authorised' ? ['hold_status' => 'voided', 'captured_minor' => 0] : []))->save();
                            log_activity('order.late_hold_lapsed', $locked, ['order_id' => $locked->id], (int) $locked->store_id);

                            return true;
                        });
                        if ($voided) {
                            $count++;
                        }
                    }
                });
        });

        return $count;
    }

    /** A scheduled order's slot must still be in the future and not full; an ASAP order has no slot to take. */
    private function slotFree(Order $order): bool
    {
        if ($order->scheduled_at === null) {
            return true;
        }
        $at = Carbon::parse($order->scheduled_at);
        if ($at->isPast()) {
            return false;
        }
        $branch = $order->branch_id ? Branch::query()->where('store_id', $order->store_id)->whereKey($order->branch_id)->first() : null;

        return ! $this->scheduling->slotIsFull((int) $order->store_id, $branch, $at);
    }

    /** Every tracked line still has its quantity in stock (and is not sold out). Advanced (recipe) stock is not checked. */
    private function stockFree(Order $order): bool
    {
        foreach ($order->items as $item) {
            $menu = Menu::query()->where('store_id', $order->store_id)->whereKey($item->item_id)->first();
            if ($menu === null) {
                continue; // a combo line or a deleted item: nothing to reserve
            }
            if ((int) $menu->is_sold_out === 1) {
                return false;
            }
            if ($menu->track_inventory && $menu->stock_qty !== null && (int) $menu->stock_qty < (int) $item->quantity) {
                return false;
            }
            if ((int) $item->variation) {
                $variant = MenuVariant::query()->where('menu_id', $menu->id)->whereKey($item->variation)->first();
                if ($variant !== null && $variant->stock_qty !== null && (int) $variant->stock_qty < (int) $item->quantity) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Take the order's stock again (the cancellation gave it back), the way OrderApiService::persistLines takes it:
     * P6-103 — the conditional reserveStock (`where stock_qty >= qty`), all lines or none (a savepoint), so a late
     * payment accepted after another shopper bought the last unit never drives stock negative.
     *
     * @return bool false = a line no longer has its quantity (nothing was taken)
     */
    private function reserveStock(Order $order): bool
    {
        if ($order->stock_restored_at === null) {
            return true; // never given back (e.g. a held order) — nothing to take again
        }
        $advanced = inventory_mode((int) $order->store_id) === 'advanced';
        try {
            DB::transaction(function () use ($order, $advanced) {
                foreach ($order->items as $item) {
                    if ((int) $item->item_id <= 0) {
                        continue;
                    }
                    if ($advanced) {
                        InventoryService::deductForOrderItem((int) $item->item_id, (int) $item->quantity, $order->id, $order->branch_id ?: null);
                    } elseif (! Menu::reserveStock((int) $item->item_id, (int) $item->quantity)) {
                        throw new \UnderflowException('p6-103 stock taken');
                    }
                    if ((int) $item->variation && ! MenuVariant::reserveStock((int) $item->variation, (int) $item->quantity)) {
                        throw new \UnderflowException('p6-103 stock taken');
                    }
                }
            });
        } catch (\UnderflowException) { // only thrown above: undo the savepoint
            return false;
        }
        $order->stock_restored_at = null;

        return true;
    }
}
