<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CouponService;
use App\Services\CustomerWalletService;
use App\Services\FeatureAccess;
use App\Services\GiftCardService;
use App\Services\PlatformMetrics;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Contracts\PromotionRules;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;

/**
 * Coupons & Wallets P7-1a — the ONE shared implementation of "given a persisted
 * order + a coupon_code + a wallet intent, resolve the owner coupon, record the
 * discount, and (for an identified customer who opted in) spend store credit."
 *
 * Every order surface calls this: the web storefront (StoreController::submitOrder)
 * and every surface behind OrderApiService::create (agent/MCP, customer-app,
 * owner-REST, POS, staff tableside). There is no second copy of these pricing
 * rules — that is decision D1.
 *
 * Server-authoritative (D2): the caller passes intent only (coupon_code,
 * use_wallet/wallet_amount); the discount and the applied credit are computed
 * here from server data. Store credit is account-bound (D3): applied only when a
 * customer is present. Credit application is idempotent per order (D4) via
 * CustomerWalletService::applyToOrder — a safe retry never double-applies.
 */
class OrderPricing
{
    /**
     * @param  array{
     *   coupon_code?: ?string,
     *   owner_id?: ?int,
     *   subtotal?: ?float,
     *   customer_id?: ?int,
     *   branch_id?: ?int,
     *   use_wallet?: bool,
     *   wallet_amount?: ?float,
     *   wallet_customer?: ?Customer,
     * }  $ctx
     * @return array{discount: float, coupon_id: ?int, free_delivery: bool, wallet_applied: float, gift_card_applied: float, claim_denied: bool, claim_denied_reason: ?string}
     */
    public static function applyCouponAndCredit(Order $order, array $ctx): array
    {
        $discount = 0.0;
        $couponId = null;
        $freeDelivery = false;
        $claimDenied = false;   // C5
        $claimDeniedReason = null;   // M3: 'per_customer' | 'limit' | null

        $code = isset($ctx['coupon_code']) ? trim((string) $ctx['coupon_code']) : '';
        /* P1-23 (AUP 3.1(d)): no coupon on an order holding medicines where the pharmacy's market forbids
           promotions — every surface (storefront, goods checkout, API, POS) passes through here. The order is
           placed at full price; claim_denied tells the caller why. */
        if ($code !== '' && $order->coupon_id === null && static::promotionsBlocked($order)) {
            $code = '';
            $claimDenied = true;
            $claimDeniedReason = 'promotions_restricted';
        }
        // Guard: only resolve when the order isn't already couponed. Keeps coupon
        // application idempotent — a re-entry never re-increments `used` or
        // double-adds the discount (D4).
        if ($code !== '' && $order->coupon_id === null) {
            $subtotal = array_key_exists('subtotal', $ctx) && $ctx['subtotal'] !== null
                ? (float) $ctx['subtotal']
                : static::subtotalOf($order);

            $result = (new CouponService)->resolve($code, [
                'surface' => 'food_order',
                'owner_id' => $ctx['owner_id'] ?? $order->user_id ?? null,
                'subtotal' => $subtotal,
                'customer_id' => $ctx['customer_id'] ?? $order->customer_id,
                'branch_id' => $ctx['branch_id'] ?? $order->branch_id,
                // E5: a BOGO coupon needs the cart lines to price itself; this is
                // the binding settlement seam (the pre-flight has subtotal only).
                'order' => $order,
                // Bug batch 4 · 2: the discount rounds to the order currency (KWD fils), as the totals do.
                'currency' => $order->minorMoneyCurrency(),
            ]);

            /* C5: burn the use ATOMICALLY, and do it BEFORE the discount is
               written. resolve()'s `used < limit` gate above is check-then-act:
               two concurrent redemptions of a limit=1 coupon both clear it. The
               old code wrote discount_amount, saved the order, and only then did a
               plain increment('used') — so a losing racer had already banked the
               discount. Gating the whole apply block on the atomic claims means the
               loser writes nothing: no coupon_id, no discount, order still placed
               at full price. Idempotent per order via the coupon_id === null guard
               above. See CouponService::claimUsage() / claimForCustomer(). */
            if (! empty($result['ok'])) {
                /* M3: claim the PER-CUSTOMER slot BEFORE the global claim, and
                   before any discount is written.

                   Order matters and this direction is deliberate. If the global
                   claim ran first and the per-customer claim then failed, we would
                   have burned one use of a SHARED coupon for an order that gets no
                   discount — a silent loss the merchant cannot see. Claiming the
                   per-customer slot first means the only compensating action needed
                   is deleting a row we ourselves just inserted, by primary key.

                   Documented tradeoff: if the process dies between a successful
                   claimForCustomer() and the order being written, one slot is burned
                   with no discount — the deliberate direction to fail (a merchant can
                   delete the stray coupon_redemptions row; a second free redemption
                   is unrecoverable). Same reasoning C5 records for WalletPromoService.

                   The `$order->coupon_id === null` guard above keeps the whole thing
                   idempotent per order: a re-entry never re-claims either counter. */
                $redemption = CouponService::claimForCustomer(
                    $result['coupon'],
                    (int) ($ctx['customer_id'] ?? $order->customer_id ?? 0) ?: null,
                    (int) $order->id
                );

                if ($redemption === false) {
                    $claimDenied = true;
                    $claimDeniedReason = 'per_customer';
                    static::recordClaimDenial($order, $ctx, 'per_customer');
                    PlatformMetrics::record('coupon.per_customer_denied', 'food_order');
                } elseif (! CouponService::claimUsage($result['coupon'])) {
                    // Global limit lost the race — hand the per-customer slot back.
                    CouponService::releaseCustomerClaim($redemption);
                    $claimDenied = true;
                    $claimDeniedReason = 'limit';
                    static::recordClaimDenial($order, $ctx, 'food_order');
                } else {
                    $discount = (float) $result['discount'];
                    $couponId = $result['coupon']->id;
                    $freeDelivery = ! empty($result['free_delivery']);

                    $order->coupon_id = $couponId;
                    // ADD to any existing discount (e.g. a POS manual discount_amount) —
                    // the coupon is distinct from and stacks on the manual discount.
                    $dec = Currencies::exponent($order->minorMoneyCurrency());
                    $order->discount_amount = Money::round((float) ($order->discount_amount ?? 0) + $discount, $dec);

                    /* A free-delivery coupon has to reach the AUTHORITATIVE total.
                       Order::computeMoney() always adds the store's delivery charge
                       for a delivery order and knows nothing about the coupon, so
                       waiving it only in the caller's local arithmetic meant the
                       guest saw "free delivery" and was still charged for it.
                       Recording the waived charge as discount is what computeMoney
                       already subtracts, so every surface — total, amount_due, the
                       gateway charge and the reports — agrees. */
                    if ($freeDelivery && $order->type === 'delivery') {
                        // Bug batch 4 · 3: waive the fee the order charges (a marketplace order's zone fee).
                        $deliveryCharge = $order->deliveryCharge();
                        if ($deliveryCharge > 0) {
                            $order->discount_amount = Money::round((float) $order->discount_amount + $deliveryCharge, $dec);
                        }
                    }

                    $order->save();
                }
            }
        }

        $walletApplied = 0.0;
        if (! empty($ctx['use_wallet'])) {
            $customer = $ctx['wallet_customer'] ?? null;
            if (! ($customer instanceof Customer) && ! empty($ctx['customer_id'])) {
                $customer = Customer::find($ctx['customer_id']);
            }

            if ($customer instanceof Customer) {
                $requested = array_key_exists('wallet_amount', $ctx) && $ctx['wallet_amount'] !== null
                    ? (float) $ctx['wallet_amount']
                    : CustomerWalletService::balanceFor($customer);

                // fresh() so Order->total re-derives from the persisted items and
                // the just-saved discount (the items relation may be cached empty).
                $fresh = $order->fresh();
                $walletApplied = CustomerWalletService::applyToOrder($customer, $fresh, $requested);
                // Reflect the payment on the caller's instance.
                $order->wallet_applied = $fresh->wallet_applied;
            }
        }

        // T2: gift-card redemption (tender) — applied AFTER discount + wallet so it
        // only reduces amount_due. Tenant-scoped resolve; invalid code throws.
        $giftCardApplied = 0.0;
        $code = isset($ctx['gift_card_code']) ? trim((string) $ctx['gift_card_code']) : '';
        if ($code !== '') {
            // Graceful: an invalid/drained code applies nothing (callers that want to
            // reject a typo validate up-front via GiftCardService::findRedeemable).
            $card = GiftCardService::findRedeemable($code, (int) $order->store_id);
            if ($card) {
                $fresh = $order->fresh();
                $giftCardApplied = GiftCardService::applyToOrder($card, $fresh);
                /* H6: GiftCardService::applyToOrder re-reads the order internally and
                   does NOT mutate the $fresh we passed (unlike CustomerWalletService
                   above), so $fresh->gift_card_applied is still the pre-apply 0.
                   Reload it before copying onto $order — otherwise, now that order
                   creation is transactional, the snapshotTotals() saveQuietly on the
                   caller's $order persists that stale 0 over the amount applyToOrder
                   just wrote. Before H6 the applyToOrder write autocommitted and
                   survived; the reload makes the outcome correct either way. */
                $fresh->refresh();
                $order->gift_card_applied = $fresh->gift_card_applied;
                $order->gift_card_id = $fresh->gift_card_id;
            }
        }

        return [
            'discount' => $discount,
            'coupon_id' => $couponId,
            'free_delivery' => $freeDelivery,
            'wallet_applied' => $walletApplied,
            'gift_card_applied' => $giftCardApplied,
            'claim_denied' => $claimDenied,
            'claim_denied_reason' => $claimDeniedReason,
        ];
    }

    /**
     * C5: an exhausted coupon is not an error — the order is placed at full price —
     * but the operator has to be able to see it, or a coupon that ran out looks
     * like "the discount silently stopped working". Two counters, both
     * fire-and-forget: a per-tenant one the merchant/reseller dashboards read, and
     * a platform one the superadmin overview reads.
     *
     * @param  array<string, mixed>  $ctx
     */
    protected static function recordClaimDenial(Order $order, array $ctx, string $surface): void
    {
        try {
            PlatformMetrics::record('coupon.claim_denied', $surface);
            $ownerId = (int) ($ctx['owner_id'] ?? $order->user_id ?? 0);
            if ($ownerId > 0) {
                $resellerId = User::whereKey($ownerId)->value('reseller_id');
                TenantUsageDaily::bump($ownerId, 'coupon_claim_denied', 1, $resellerId ? (int) $resellerId : null);
            }
        } catch (\Throwable $e) {
            // metering must never fail an order
        }
    }

    /**
     * K3 loyalty reward at checkout — the ONE implementation (was copied into the storefront checkout, the goods
     * checkout and OrderApiService). Spends the program's reward_threshold points for the SERVER-derived
     * reward_discount, added to the order discount BEFORE coupon / wallet / gift card so those tenders size against
     * the reduced total. No-op for guests, stores without an active program or without the loyalty feature.
     *
     * P1-23 follow-up (pharmacy promotion block): points are never redeemed on lines promotions may not touch — the
     * reward must fit inside the order's other lines, else nothing is spent and the reason is
     * 'promotions_restricted'.
     *
     * @return array{redeemed: float, denied_reason: ?string}
     */
    public static function redeemLoyalty(Order $order, int $storeId): array
    {
        $none = ['redeemed' => 0.0, 'denied_reason' => null];
        if (! $order->customer_id || ! FeatureAccess::storeAllows($storeId, 'loyalty')) {
            return $none;
        }
        $lp = LoyaltyProgram::where('store_id', $storeId)->where('active', true)->first();
        if (! $lp || (float) $lp->reward_discount <= 0 || (int) $lp->reward_threshold <= 0) {
            return $none;
        }
        // Bug batch 4 · 2: the ORDER currency's decimals (a KWD store's 0.125 reward stays 0.125).
        $dec = Currencies::exponent($order->minorMoneyCurrency());
        $reward = Money::round((float) $lp->reward_discount, $dec);

        $split = static::promotionSplit($order);
        if ($split['blocked'] > 0 && $reward > Money::round($split['subtotal'] - $split['blocked'], $dec)) {
            return ['redeemed' => 0.0, 'denied_reason' => 'promotions_restricted'];
        }

        $acct = LoyaltyAccount::forCustomer((int) $order->customer_id, $storeId);
        if ($acct->balance < (int) $lp->reward_threshold
            || ! $acct->redeem((int) $lp->reward_threshold, 'Redeemed at checkout — Order #'.$order->id, (int) $order->id)) {
            return $none;
        }
        $order->discount_amount = Money::round((float) ($order->discount_amount ?? 0) + $reward, $dec);
        $order->save();

        return ['redeemed' => $reward, 'denied_reason' => null];
    }

    /**
     * P1-23 follow-up: the short customer-facing reason when a coupon or a loyalty reward the customer asked for did
     * not apply because the order holds lines promotions may not touch; null otherwise.
     */
    public static function promotionsNote(Order $order, bool $couponAsked, bool $loyaltyAsked): ?string
    {
        $order = $order->fresh() ?? $order;
        $couponRefused = $couponAsked && $order->coupon_id === null;
        $loyaltyRefused = $loyaltyAsked && ! LoyaltyLedger::where('order_id', $order->id)->where('type', 'redeem')->exists();
        if (! $couponRefused && ! $loyaltyRefused) {
            return null;
        }

        return static::promotionSplit($order)['blocked'] > 0 ? app(PromotionRules::class)->reason() : null;
    }

    /**
     * P1-23 follow-up: the order's item subtotal and the part of it on lines promotions may not touch (medicines of
     * a pharmacy whose market blocks promotions). `blocked` is 0 for every other store. Read from the persisted
     * lines (a fresh copy, so a relation cached before the lines were written cannot hide them).
     *
     * @return array{subtotal: float, blocked: float}
     */
    public static function promotionSplit(Order $order): array
    {
        $rules = app(PromotionRules::class);
        if (! $order->store_id || ! $rules->storeBlocksPromotions((int) $order->store_id)) {
            return ['subtotal' => 0.0, 'blocked' => 0.0];
        }
        $fresh = $order->exists ? ($order->fresh() ?? $order) : $order;

        return [
            'subtotal' => (float) $fresh->itemsSubtotal(),
            'blocked' => (float) $fresh->itemsSubtotal(fn (OrderItem $item) => $item->menu !== null && $rules->blocksPromotions($item->menu)),
        ];
    }

    /** P1-23: whether the store's sales rules forbid promotions on this order's items. */
    protected static function promotionsBlocked(Order $order): bool
    {
        $store = $order->store_id ? Store::find($order->store_id) : null;
        if ($store === null) {
            return false;
        }

        return app(SalesRestrictions::class)->promotionsBlocked($store, $order->items()->with('menu')->get()->pluck('menu')->filter()->all());
    }

    /**
     * Discountable subtotal from the persisted order items — the coupon discount base
     * and the min-order comparison — when the caller does not pass an explicit subtotal.
     *
     * This MUST be the same pre-tax/pre-discount item subtotal that the authoritative
     * total is built from, so it delegates to Order::computeMoney()['subtotal'] — the
     * single derivation getTotalAttribute() and snapshotTotals() also read. The old
     * hand-rolled loop diverged from it in three ways, each producing a wrong coupon:
     *   · it read $item->menu->price and ignored the snapshotted $item->unit_price
     *     scheduled (happy-hour) price, so a percentage coupon discounted off the list
     *     price and over-discounted the order (store loss);
     *   · it skipped combo lines (`! $item->menu` → combos have item_id = NULL), so a
     *     combo-only order had subtotal 0 and the coupon silently did nothing;
     *   · it omitted priced choice-group options, under-discounting and wrongly failing
     *     the coupon's min_order_amount check.
     * Delegating removes the divergence at the source (one derivation, forever in sync).
     */
    protected static function subtotalOf(Order $order): float
    {
        return (float) $order->computeMoney()['subtotal'];
    }
}
