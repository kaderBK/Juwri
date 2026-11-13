<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Payments;

use App\Models\Order;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use App\Services\Loyalty\LoyaltyAccrual;
use App\Services\PosService;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P3-06b (REVIEW, Opus: payments) — the core-hosted payment of a headless storefront order (kit/api README §2.12,
 * API-2, API-10):
 *
 *  - {@see open()}: the pending `order` Transaction the existing pay page settles (PaymentController index/pay →
 *    gateway → paySuccess), charged what is still owed (Order::amount_due, after store credit / gift card tenders),
 *    with the client's `return_url` kept for the hand-off. One per order: a replayed submit reuses it.
 *  - {@see expire()} / {@see sweep()}: the 15-minute window. An order still `unpaid` when it ends is cancelled
 *    (`cancelled_at`), its stock comes back and every tender it spent is reversed — exactly the owner's cancel
 *    (ManagesOrders::updateOrderStatus). Run per minute by `orders:expire-hosted-payments`, and lazily when a client
 *    reads the order (so a poll never shows an open payment after its window).
 *
 * A payment the gateway confirms after that is a late payment ({@see LatePayments}); it never revives the order.
 */
final class HostedOrderPayments implements HostedPayments
{
    public function storeCanTake(Store $store, float $total): bool
    {
        return PaymentController::hasStoreGateway($store, $total);
    }

    public function storeCanHold(Store $store, float $amount): bool
    {
        return PaymentController::hasStoreGateway($store, $amount, true);
    }

    public function open(Store $store, Order $order, ?string $returnUrl, ?string $ip, ?int $holdMinor = null): Transaction
    {
        $existing = Transaction::query()
            ->where('transaction_method', 'order')
            ->where('store_id', $store->id)
            ->where('product_id', $order->id)
            ->orderBy('id')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $due = (float) $order->fresh()?->amount_due;
        $currency = strtoupper((string) ($order->currency_code ?: store_options($store->getKey(), 'currency_code')));
        if ($holdMinor !== null) {
            // P3-09: a weighed basket's card hold covers what is still owed + what the weighed lines may add (never less)
            $headroom = max(0, $holdMinor - Money::minorFromMajor((float) $order->fresh()?->total, $currency));
            $due = Money::majorFromMinor(Money::minorFromMajor($due, $currency) + $headroom, $currency);
        }
        $label = sprintf('%s (#%s)', $store->title, $order->getKey());

        return Transaction::create([
            'product_name' => $label,
            'transaction_description' => $label,
            'product_id' => $order->getKey(),
            'store_id' => $store->getKey(), // order payments: the store; user_id stays NULL (the diner is not a user)
            'amount' => $due,
            'base_amount' => $due,
            'currency_code' => $order->currency_code ?: store_options($store->getKey(), 'currency_code'),
            'transaction_method' => 'order',
            'transaction_ip' => $ip,
            'details' => array_filter([
                'source' => 'storefront_api',
                'return_url' => $returnUrl,
                // P3-09: authorise this amount and capture the weighed final amount later (AuthorisesPayments gateways)
                'capture' => $holdMinor !== null ? 'manual' : null,
                'customer_name' => $order->customer_name,
                'phone' => $order->phone_number,
            ], fn ($v) => $v !== null && $v !== ''),
        ]);
    }

    /** The order's pending payment row, if it was opened. */
    public function transactionFor(Order $order): ?Transaction
    {
        return Transaction::query()
            ->where('transaction_method', 'order')
            ->where('store_id', $order->store_id)
            ->where('product_id', $order->id)
            ->orderBy('id')
            ->first();
    }

    /** Is this order's hosted payment window still open? */
    public function isOpen(Order $order): bool
    {
        return $order->payment_mode === 'hosted'
            && $order->status === 'unpaid'
            && $order->payment_expires_at !== null
            && $order->payment_expires_at->isFuture();
    }

    /**
     * Cancel the order when its hosted window has ended unpaid. Idempotent (re-checked under the row lock), so the
     * sweep, a lazy read and a concurrent paySuccess never cancel twice or cancel a paid order.
     */
    public function expire(Order $order): bool
    {
        return $this->cancelUnpaid($order, false, 'order.hosted_payment_expired');
    }

    /**
     * P3-09 (API-24a): the shopper released the slot hold of their pending hosted order while the store has "Release
     * holds during payment" on — the order is cancelled now, exactly as at the end of its window (the payment page stops
     * taking payment; a payment the gateway still confirms is a late payment, API-21).
     */
    public function cancelByCustomer(Order $order): bool
    {
        return $this->cancelUnpaid($order, true, 'order.hosted_payment_released');
    }

    private function cancelUnpaid(Order $order, bool $now, string $event): bool
    {
        $cancelled = DB::transaction(function () use ($order, $now) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($locked === null || $locked->payment_mode !== 'hosted' || $locked->status !== 'unpaid' || (int) $locked->is_paid === 1
                || $locked->payment_expires_at === null || (! $now && $locked->payment_expires_at->isFuture())) {
                return null;
            }
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

            return $locked;
        });
        if (! $cancelled instanceof Order) {
            return false;
        }

        // What the owner's cancel gives back (ManagesOrders::updateOrderStatus): stock, gift card, store credit and
        // loyalty points. Each primitive is idempotent and no-op when there is nothing to reverse.
        PosService::restoreOrderStock($cancelled);
        GiftCardService::reverseRedemption($cancelled);
        CustomerWalletService::reverseForOrder($cancelled);
        LoyaltyAccrual::reverseForOrder($cancelled);
        LoyaltyAccrual::restoreRedemptionForOrder($cancelled);
        log_activity($event, $cancelled, ['order_id' => $cancelled->id], (int) $cancelled->store_id);
        $order->refresh();

        return true;
    }

    /** Cancel every hosted order whose window has ended unpaid (all stores; platform job). */
    public function sweep(): int
    {
        $count = 0;
        TenantContext::bypass(function () use (&$count) {
            Order::query()
                ->where('status', 'unpaid')
                ->where('payment_mode', 'hosted')
                ->whereNotNull('payment_expires_at')
                ->where('payment_expires_at', '<=', now())
                ->orderBy('id')
                ->chunkById(200, function ($orders) use (&$count) {
                    foreach ($orders as $order) {
                        if ($this->expire($order)) {
                            $count++;
                        }
                    }
                });
        });

        return $count;
    }
}
