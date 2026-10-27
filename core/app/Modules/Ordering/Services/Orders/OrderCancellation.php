<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use App\Models\Order;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use App\Services\Loyalty\LoyaltyAccrual;
use App\Services\PosService;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Modules\Ordering\Enums\OrderStatus;
use Juwri\Modules\Tables\Models\CheckPayment;

/**
 * P6-205/207: the one cancel routine shared by the owner dashboard, the staff PWA and
 * POS reject. A cancel gives back stock, folio charge, gift-card tender, wallet tender
 * and loyalty (earned + redeemed). Every primitive is idempotent, so a retried or
 * replayed cancel never double-credits. Cancelled is terminal: once stock and money
 * went back, the order cannot become a sale again.
 */
final class OrderCancellation
{
    /** A cancelled order may only stay cancelled (P6-207). */
    public static function blocksTransition(Order $order, string $to): bool
    {
        return $order->status === OrderStatus::Cancelled->value && $to !== OrderStatus::Cancelled->value;
    }

    /** A split bill with PAID shares must be refunded or settled, never cancelled. */
    public static function hasPaidSplitShares(Order $order): bool
    {
        return CheckPayment::whereHas('tableCheck', fn ($q) => $q->where('order_id', $order->id))
            ->where('status', 'paid')->exists();
    }

    /** Reverse everything a placed order took (call after the status is saved as cancelled). */
    public static function reverse(Order $order): void
    {
        PosService::restoreOrderStock($order);
        self::reverseFolio($order);
        GiftCardService::reverseRedemption($order);
        CustomerWalletService::reverseForOrder($order);
        LoyaltyAccrual::reverseForOrder($order);
        LoyaltyAccrual::restoreRedemptionForOrder($order);
    }

    /**
     * A3: reverse a charge-to-room folio charge. Best-effort: a ledger failure never
     * blocks the cancel; a rejected reversal (folio settled) is logged for A5.
     */
    private static function reverseFolio(Order $order): void
    {
        if ($order->payment_method !== 'charge_to_room') {
            return;
        }
        try {
            $rev = app(RoomCharge::class)->reverseOrderCharge($order);
            if ($rev['state'] === 'pending') {
                log_activity('hotel.folio.reversal_pending', $order, ['order_id' => $order->id, 'amount' => $rev['amount'] ?? null], $order->store_id);
            } elseif ($rev['state'] === 'reversed') {
                log_activity('hotel.folio.reversed', $order, ['order_id' => $order->id, 'amount' => $rev['amount'] ?? null], $order->store_id);
            }
        } catch (\Throwable $e) {
            Log::warning('folio reversal failed', ['order' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
