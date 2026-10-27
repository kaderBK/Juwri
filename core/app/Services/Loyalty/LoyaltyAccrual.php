<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Legal\CookieConsent;
use Juwri\Modules\Ordering\Services\Orders\OrderPricing;

/**
 * The ONE loyalty accrual seam. Every order-creation / settlement path calls this so
 * the "earns ~N" preview (POS register, storefront) always matches what actually
 * accrues. Previously only the storefront tail earned points, so POS and marketplace
 * sales advertised an earn and credited nothing.
 *
 * Idempotent per order (LoyaltyAccount::earn dedups on the order_id unique index) and
 * self-guarded — a loyalty failure must never fail the order or the payment.
 */
class LoyaltyAccrual
{
    /**
     * Earn stamps/points for an order. `$total` overrides the accrual base (the
     * storefront passes its authoritative local total); null derives it from the order.
     */
    public static function earnForOrder(Order $order, ?float $total = null): void
    {
        if (! $order->customer_id) {
            return;
        }

        try {
            // Program earn (skipped when the store runs no active program — E4:
            // this used to `return` and now merely skips, so referral crediting
            // below still runs for stores without a points/stamps program).
            $program = LoyaltyProgram::where('store_id', $order->store_id)
                ->where('active', true)->first();

            if ($program) {
                $base = $total ?? (float) $order->total;
                $stamp = 1;
                /* P1-23 follow-up (pharmacy promotion block): no points on lines promotions may not touch (medicines
                   where the market blocks promotions). Points earn on the other lines' share of the order; a stamp
                   needs at least one such line. */
                $split = OrderPricing::promotionSplit($order);
                if ($split['blocked'] > 0) {
                    $eligible = max(0.0, $split['subtotal'] - $split['blocked']);
                    $base = $split['subtotal'] > 0 ? $base * $eligible / $split['subtotal'] : 0.0;
                    $stamp = $eligible > 0 ? 1 : 0;
                }
                $delta = $program->type === 'stamps'
                    ? $stamp
                    : (int) floor($base * $program->earn_rate / 100);

                if ($delta > 0) {
                    LoyaltyAccount::forCustomer((int) $order->customer_id, (int) $order->store_id)
                        ->earn((int) $order->id, $delta, 'Order #'.$order->id);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Loyalty earn failed: '.$e->getMessage());
        }

        // E4: guest refer-a-friend — credited on the referee's first paid order.
        self::creditReferral($order);
    }

    /**
     * E4: on a referee's first paid order, credit the referrer and the referee
     * their store's configured referral points. Both sides are credited at most
     * once (note-keyed idempotency); self-referral is blocked; the feature is dark
     * until the owner sets a non-zero `loyalty_referral_points_*` option.
     */
    private static function creditReferral(Order $order): void
    {
        if (! $order->customer_id || ! $order->is_paid) {
            return;
        }
        try {
            $referee = Customer::find($order->customer_id);
            if (! $referee) {
                return;
            }

            // Resolve the code they arrived with: the persisted value, else the
            // `mb_ref` visit cookie (persisted here on first use).
            $code = $referee->referred_by_code;
            if (empty($code) && ($cookie = CookieConsent::attribution(request(), 'mb_ref'))) {
                $code = (string) $cookie;
                $referee->forceFill(['referred_by_code' => $code])->save();
            }
            if (empty($code)) {
                return;
            }

            $referrer = Customer::where('store_id', $order->store_id)
                ->where('referral_code', $code)->first();
            if (! $referrer || (int) $referrer->id === (int) $referee->id) {
                return; // unknown code or self-referral
            }

            $referrerPts = (int) store_options($order->store_id, 'loyalty_referral_points_referrer', '0');
            $refereePts = (int) store_options($order->store_id, 'loyalty_referral_points_referee', '0');
            if ($referrerPts <= 0 && $refereePts <= 0) {
                return; // feature dark until the owner sets values
            }

            if ($referrerPts > 0) {
                LoyaltyAccount::forCustomer((int) $referrer->id, (int) $order->store_id)
                    ->creditOnce($referrerPts, 'Referral bonus (invited customer #'.$referee->id.')');
            }
            if ($refereePts > 0) {
                LoyaltyAccount::forCustomer((int) $referee->id, (int) $order->store_id)
                    ->creditOnce($refereePts, 'Referral welcome bonus');
            }
        } catch (\Throwable $e) {
            Log::warning('Loyalty referral credit failed: '.$e->getMessage());
        }
    }

    /**
     * Claw back the points earned for an order (on refund) — a refunded sale forfeits
     * its points. Idempotent (one reversal per order) and self-guarded; a no-op when the
     * order never earned or has no customer.
     */
    public static function reverseForOrder(Order $order, ?float $share = null): void
    {
        if (! $order->customer_id) {
            return;
        }

        try {
            $account = LoyaltyAccount::forCustomer((int) $order->customer_id, (int) $order->store_id);
            $took = $account->reverseEarn((int) $order->id, 'Reversed earn — refund — Order #'.$order->id, self::shareOfEarn($order, $share));
            if ($took) {
                // P6-253 (J46): tell the customer once per claw-back (reverseEarn is a no-op on a repeat).
                LoyaltyClawbackNotice::send($order, $account->lastClawed, (int) $account->balance);
            }
        } catch (\Throwable $e) {
            Log::warning('Loyalty earn reversal failed: '.$e->getMessage());
        }
    }

    /**
     * P6-240: points to forfeit for a refunded share (0..1) of the order — null = all.
     * Rounded up so a refund never leaves a fraction of a point unforfeited.
     */
    private static function shareOfEarn(Order $order, ?float $share): ?int
    {
        if ($share === null || $share >= 1) {
            return null;
        }
        $earned = (int) LoyaltyLedger::where('order_id', $order->id)->where('type', 'earn')->value('delta');

        return (int) ceil(max(0.0, $share) * $earned - 1e-9);
    }

    /**
     * Give back the points a customer REDEEMED on an order that is now fully reversed
     * (cancel / void / full refund). The reward discount is reversed together with the
     * order, so the points spent on it must be restored — otherwise the customer forfeits
     * both. The counterpart to reverseForOrder (which forfeits EARNED points). Fire this
     * ONLY on a full reversal: a partial refund keeps the reward discount, so its points
     * stay spent. Idempotent (one restore per order) and self-guarded — a no-op when the
     * order redeemed nothing or has no customer.
     */
    public static function restoreRedemptionForOrder(Order $order): void
    {
        if (! $order->customer_id) {
            return;
        }

        try {
            LoyaltyAccount::forCustomer((int) $order->customer_id, (int) $order->store_id)
                ->restoreRedeem((int) $order->id, 'Restored redemption — Order #'.$order->id);
        } catch (\Throwable $e) {
            Log::warning('Loyalty redemption restore failed: '.$e->getMessage());
        }
    }
}
