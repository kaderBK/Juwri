<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use App\Models\Order;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use App\Services\Loyalty\LoyaltyAccrual;
use App\Services\PosService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Contracts\Marketplace\PlatformWalletTender;
use Juwri\Core\Money\Money;
use Juwri\Modules\Ordering\Enums\OrderStatus;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
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

    /**
     * Reverse everything a placed order took (call after the status is saved as cancelled).
     *
     * money-last 5 (REVIEW, Opus: money): each step runs on its own, so one that fails (a ledger or wallet error) never
     * skips the steps after it and never undoes the cancel. The failure is logged (`order.cancel_step_failed`) and,
     * since every step is idempotent and a cancelled order may be cancelled again, cancelling again retries only what
     * did not happen.
     */
    public static function reverse(Order $order): void
    {
        $steps = [
            'stock' => fn () => PosService::restoreOrderStock($order),
            'folio' => fn () => self::reverseFolio($order),
            'tenders' => fn () => self::reverseTenders($order),
            'loyalty_earned' => fn () => LoyaltyAccrual::reverseForOrder($order),
            'loyalty_redeemed' => fn () => LoyaltyAccrual::restoreRedemptionForOrder($order),
        ];
        foreach ($steps as $step => $run) {
            try {
                $run();
            } catch (\Throwable $e) {
                report($e);
                log_activity('order.cancel_step_failed', $order, [
                    'order_id' => $order->id, 'step' => $step, 'error' => $e->getMessage(),
                ], (int) $order->store_id);
            }
        }
    }

    /**
     * Give the stored-value tenders (store credit, gift card, platform wallet) of a cancelled, voided or fully refunded
     * order back, in ONE transaction under the order lock: all three or none, so a retry after a failure starts again
     * from the same tenders.
     *
     * money-last 2 (REVIEW, Opus: money): the part of the tenders already refunded to store credit (refund rows with
     * method `store_credit`) is not given back a second time. A refund to store credit counts against the tenders first
     * (store credit, then gift card, then platform wallet): the card or cash part stays refundable to the original
     * payment by its own cap, so this never pays twice and never strands money the owner cannot still refund.
     *
     * money-last2 (REVIEW, Opus: money): what came off the order is recorded on it, under the same lock, for every refund
     * cap (OrderTenders): `tenders_returned_minor` (given back to the tender) and `tenders_kept_minor` (covered by a refund
     * to store credit). Measured as the drop of the tender columns, so a step that returned nothing (card gone, customer
     * gone, no platform spend) records nothing.
     */
    public static function reverseTenders(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            $before = OrderTenders::currentMinor($locked);
            $mkBefore = Money::minorFromMajor((float) ($locked->mk_wallet_applied ?? 0), $locked->minorMoneyCurrency());
            [$keptMinor, $keepPlatform] = self::keepTendersRefundedToCredit($locked);
            GiftCardService::reverseRedemption($locked);
            CustomerWalletService::reverseForOrder($locked);
            self::reversePlatformWallet($locked, $keepPlatform);

            $after = Order::query()->whereKey($locked->id)->first();
            if ($after === null) {
                return;
            }
            $currency = $after->minorMoneyCurrency();
            // the platform-wallet part kept counts only when the wallet reversal took it off the order
            $mkGone = max(0, $mkBefore - Money::minorFromMajor((float) ($after->mk_wallet_applied ?? 0), $currency));
            $kept = $keptMinor + min($mkGone, Money::minorFromMajor($keepPlatform, $currency));
            $returned = max(0, $before - OrderTenders::currentMinor($after) - $kept);
            if ($returned > 0 || $kept > 0) {
                $after->forceFill([
                    'tenders_returned_minor' => OrderTenders::returnedMinor($after) + $returned,
                    'tenders_kept_minor' => OrderTenders::keptMinor($after) + $kept,
                ])->save();
            }
        });
    }

    /**
     * Lower the store-credit and gift-card tenders of the locked order by what refunds to store credit already gave
     * back and no earlier reversal kept (they are then not returned again). Returns that store-credit / gift-card part
     * (minor units) and the platform-wallet part those refunds covered (order currency; the wallet reversal keeps it).
     *
     * @return array{0: int, 1: float}
     */
    private static function keepTendersRefundedToCredit(Order $locked): array
    {
        $currency = $locked->minorMoneyCurrency();
        $covered = -OrderTenders::keptMinor($locked);
        foreach (OrderAdjustment::query()->where('order_id', $locked->id)->where('type', 'refund')->where('method', 'store_credit')->get() as $row) {
            $covered += Money::minorFromMajor((float) $row->amount, $currency);
        }
        if ($covered <= 0) {
            return [0, 0.0];
        }
        $kept = [];
        $keptMinor = 0;
        foreach (OrderTenders::COLUMNS as $tender) {
            $applied = max(0, Money::minorFromMajor((float) ($locked->getAttribute($tender) ?? 0), $currency));
            $keep = min($applied, $covered);
            if ($keep <= 0) {
                continue;
            }
            $covered -= $keep;
            $kept[$tender] = Money::majorFromMinor($keep, $currency);
            if ($tender !== 'mk_wallet_applied') {
                $locked->setAttribute($tender, Money::majorFromMinor($applied - $keep, $currency));
                $keptMinor += $keep;
            }
        }
        if ($kept === []) {
            return [0, 0.0];
        }
        $locked->save();
        log_activity('order.tenders_kept_for_credit_refund', $locked, ['order_id' => $locked->id] + $kept, (int) $locked->store_id);

        return [$keptMinor, (float) ($kept['mk_wallet_applied'] ?? 0.0)];
    }

    /**
     * money-tail 4: the platform (Fusion) wallet share of a cancelled, voided or fully refunded order goes back to the
     * wallet that paid it, through the edition's PlatformWalletTender (Community has none). Idempotent per order.
     * `$keep` (order currency) is a part already refunded another way (money-last 2): it is not given back.
     */
    public static function reversePlatformWallet(Order $order, float $keep = 0.0): void
    {
        $returned = app(PlatformWalletTender::class)->reverseOrder($order, $keep);
        if ($returned > 0) {
            log_activity('order.platform_wallet_returned', $order, ['order_id' => $order->id, 'amount' => $returned], (int) $order->store_id);
        }
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
