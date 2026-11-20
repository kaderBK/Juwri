<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Core\Support\PlanGate;
use Juwri\Modules\Loyalty\Models\CouponRedemption;

class CouponService
{
    /**
     * Coupons & Wallets P4-2: the single resolver for every surface. Scope is
     * derived from the coupon's own dimensions (owner user_id / reseller_id /
     * platform), and the caller's `surface` decides which scope is addressable —
     * this is what stops the tiers cross-redeeming (a food coupon on plan
     * checkout, a plan coupon in a diner cart, etc.).
     *
     * $context: [
     *   'surface'     => 'food_order' | 'plan_checkout' | 'wallet_topup',
     *   'subtotal'    => float,
     *   'owner_id'    => ?int,   // required for food_order
     *   'reseller_id' => ?int,   // set for reseller-issued plan coupons (P4-3)
     *   'currency'    => ?string, // bug batch 4 · 2: the currency charged — the discount rounds to its exponent
     *                             // (KWD fils, USD cents, JPY yen); falls back to the `order`'s, else the platform's
     * ]
     *
     * @return array{ok:bool, discount?:float, coupon?:Coupon, free_delivery?:bool, message:string}
     */
    public function resolve(string $code, array $context): array
    {
        $surface = $context['surface'] ?? null;
        $subtotal = (float) ($context['subtotal'] ?? 0);

        // P6-40: codes match case-insensitively on every driver (MySQL's ci collation did; PostgreSQL does not).
        $query = Coupon::whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))]);

        if ($surface === 'food_order') {
            $ownerId = $context['owner_id'] ?? null;
            if (! $ownerId) {
                return ['ok' => false, 'message' => ___('Coupon code is invalid.')];
            }
            // Pricing follow-ups (PRICING.md §3): an owner coupon needs a plan that includes coupons (a plan without
            // the key keeps them). Covers every surface that applies one: storefront, POS, API/agent, marketplace bag.
            if (! PlanGate::allows(User::query()->find((int) $ownerId), PlanGate::COUPONS)) {
                return ['ok' => false, 'message' => PlanGate::customerMessage(PlanGate::COUPONS)];
            }
            $query->where('user_id', $ownerId);
        } elseif ($surface === 'plan_checkout' || $surface === 'wallet_topup' || $surface === 'addon_checkout') {
            if (! empty($context['reseller_id'])) {
                // Reseller-issued coupon for a merchant under that reseller (P4-3).
                $query->where('reseller_id', $context['reseller_id']);
            } else {
                // Platform coupon: no owner, no reseller.
                $query->whereNull('user_id')->whereNull('reseller_id');
            }
        } else {
            return ['ok' => false, 'message' => ___('Coupon code is invalid.')];
        }

        $coupon = $query->first();
        if (! $coupon) {
            return ['ok' => false, 'message' => ___('Coupon code is invalid.')];
        }

        // Security (AUDIT-money M-1): a wallet credit code (applies_to = wallet_topup) is spendable platform money, a
        // plan / add-on coupon is only a discount. A plan coupon never redeems as wallet credit (its fixed value would
        // become cash-like credit, ignoring its interval / first-period rules), and a credit code is not a plan discount.
        $isCreditCode = (string) $coupon->applies_to === 'wallet_topup';
        if ($surface !== 'food_order' && ($surface === 'wallet_topup') !== $isCreditCode) {
            return ['ok' => false, 'message' => ___('Coupon code is invalid.')];
        }

        if ($coupon->expiry_at && Carbon::parse($coupon->expiry_at)->isPast()) {
            return ['ok' => false, 'message' => ___('Coupon code is expired.')];
        }

        // A null / <= 0 limit is the "unlimited" sentinel — never block it. Only a
        // positive limit gates. This is the soft, pre-flight check; the binding
        // claim is claimUsage() at settlement (resolve() alone is check-then-act
        // and two concurrent checkouts can both pass this line).
        if ((int) $coupon->limit > 0 && $coupon->used >= $coupon->limit) {
            return ['ok' => false, 'message' => ___('Coupon code usage limit exceeded.')];
        }

        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            $min = Money::ofMajor((float) $coupon->min_order_amount, $context['currency'] ?? null)->major().' '.($context['currency'] ?? '');

            return ['ok' => false, 'message' => ___('Order does not meet the minimum for this coupon.').' ('.___('Minimum').': '.$min.')'];
        }

        // P5-1: branch scope — a branch-locked coupon only applies to that branch.
        if ($coupon->branch_id !== null && empty($context['skip_branch_check']) && (int) ($context['branch_id'] ?? 0) !== (int) $coupon->branch_id) {
            return ['ok' => false, 'message' => ___('Coupon is not valid for this branch.')];
        }

        /* P5-1 · M3: per-customer usage cap. The count now comes from
           CouponRedemption::countFor(), which unions the new redemption ledger with
           the legacy order-based history — the same number claimForCustomer() will
           use, so the pre-flight and the settlement can never disagree.

           This remains the SOFT check: it is check-then-act by design, exactly like
           the `used < limit` gate above it. The binding gate is
           claimForCustomer() at settlement. `> 0` guard added because a stored 0
           would otherwise refuse everyone. */
        if ($coupon->per_customer_limit !== null && (int) $coupon->per_customer_limit > 0 && ! empty($context['customer_id'])) {
            $usedByCustomer = CouponRedemption::countFor((int) $coupon->id, (int) $context['customer_id']);
            if ($usedByCustomer >= (int) $coupon->per_customer_limit) {
                return ['ok' => false, 'message' => ___('You have already used this coupon.')];
            }
        }

        // Discount by type. free_delivery carries no subtotal discount here — the
        // caller waives the delivery charge (owner enhancement, P5-1).
        $freeDelivery = false;
        $discount = 0.00;
        $decimals = self::discountDecimals($context);
        if ($coupon->coupon_type === 'percentage') {
            $discount = Money::round(($subtotal * $coupon->percentage) / 100, $decimals);
        } elseif ($coupon->coupon_type === 'free_delivery') {
            $freeDelivery = true;
        } elseif ($coupon->coupon_type === 'bogo') {
            // E5: buy-X-get-Y is cart-shaped, so it needs the order's items. The
            // subtotal-only storefront pre-flight can't compute it (no items) and
            // returns 0 here; the binding discount is computed at settlement, where
            // OrderPricing passes the persisted order in via `order`.
            $order = $context['order'] ?? null;
            $discount = $order instanceof Order ? $this->bogoDiscount($coupon, $order) : 0.00;
        } else { // fixed
            $discount = Money::round((float) $coupon->percentage, $decimals);
        }

        // Cap the discount at the subtotal only where the subtotal is the thing
        // being discounted. A wallet_topup credit code has no subtotal to cap
        // against (a fixed credit stands alone).
        if (in_array($surface, ['food_order', 'plan_checkout', 'addon_checkout'], true) && $discount > $subtotal) {
            $discount = $subtotal;
        }

        return [
            'ok' => true,
            'discount' => $discount,
            'free_delivery' => $freeDelivery,
            'coupon' => $coupon,
            'message' => ___('Coupon applied successfully.'),
        ];
    }

    /**
     * Bug batch 5 · 1: the summed sold price of the $eligible cheapest get units ($units = [[unitPrice, qty], ...]).
     *
     * @param  list<array{0: float, 1: int}>  $units
     */
    private static function cheapestUnitsSum(array $units, int $eligible): float
    {
        usort($units, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $left = $eligible;
        $sum = 0.0;
        foreach ($units as [$price, $qty]) {
            $take = min($left, max(0, $qty));
            $sum += $take * $price;
            $left -= $take;
            if ($left <= 0) {
                break;
            }
        }

        return $sum;
    }

    /**
     * E5: buy-X-get-Y discount from an order's cart lines. For every completed
     * "buy" multiple the coupon discounts up to `get_qty` units of the get item by
     * `get_discount_percent` (100 = free). When the buy and get items are the same
     * item, buy and get units are drawn from one pool (classic "buy 1 get 1"), so
     * cycles are counted over (buy_qty + get_qty); otherwise the pools are distinct
     * and eligible get units are capped at get_qty × repeats and the cart's on-hand
     * get quantity. Combos (no item_id) never match. Returns the money discount.
     */
    public function bogoDiscount(Coupon $coupon, Order $order): float
    {
        $buyScope = $coupon->buy_scope ?: 'item';
        $buyId = (int) $coupon->buy_id;
        $buyQty = max(1, (int) $coupon->buy_qty);
        $getId = (int) $coupon->get_id;
        $getQty = max(1, (int) $coupon->get_qty);
        $pct = (float) $coupon->get_discount_percent;
        if ($buyId <= 0 || $getId <= 0 || $pct <= 0) {
            return 0.00;
        }

        $buyCartQty = 0;
        $getCartQty = 0;
        $getUnitPrice = 0.00;
        $getUnits = []; // bug batch 5 · 1: [sold unit price, quantity] per get line
        $getInBuyPool = false; // true when the get item's units are ALSO counted as buy units
        foreach ($order->items as $item) {
            $itemId = (int) ($item->item_id ?? 0);
            if ($itemId <= 0) {
                continue; // combos / non-item lines never qualify
            }
            $qty = (int) ($item->quantity ?? 0);
            $matchesBuy = $buyScope === 'category'
                ? ((int) (optional($item->menu)->category_id) === $buyId)
                : ($itemId === $buyId);
            if ($matchesBuy) {
                $buyCartQty += $qty;
            }
            if ($itemId === $getId) {
                $getCartQty += $qty;
                // Bug batch 5 · 1: the line's SOLD unit price (base / variant / scheduled price + extras + options,
                // in the order currency). `unit_price` alone is set only for a scheduled price, so it was 0 here.
                $soldUnit = $item->soldUnitPrice();
                $getUnitPrice = max($getUnitPrice, $soldUnit);
                $getUnits[] = [$soldUnit, $qty];
                // The get item also satisfies the buy condition (same item, or a
                // category-scope buy whose category contains the get item) → its units
                // share the buy pool, so the buy/get must draw from one cycle.
                if ($matchesBuy) {
                    $getInBuyPool = true;
                }
            }
        }
        if ($getCartQty <= 0 || $getUnitPrice <= 0) {
            return 0.00;
        }

        $sameItem = $getInBuyPool;
        if ($sameItem) {
            $cycle = $buyQty + $getQty;
            $eligible = intdiv($buyCartQty, $cycle) * $getQty;
        } else {
            $repeats = intdiv($buyCartQty, $buyQty);
            if ($repeats < 1) {
                return 0.00;
            }
            $eligible = min($getCartQty, $getQty * $repeats);
        }
        if ($eligible <= 0) {
            return 0.00;
        }

        // Bug batch 5 · 1: get lines can differ (options, variants) — the eligible units are the CHEAPEST ones, so the
        // discount never exceeds what those units sold for (same price on every line → that price × units). Bug batch
        // 4 · 2: rounded to the order currency's exponent (a KWD half-price 1.125 is 0.563 off, not 0.56; JPY yen).
        return Money::round(self::cheapestUnitsSum($getUnits, $eligible) * ($pct / 100), Currencies::exponent($order->minorMoneyCurrency()));
    }

    /**
     * Bug batch 4 · 2: the decimals a discount rounds to — the exponent of the currency it is charged in (the rule the
     * order totals use: every amount is stored in minor units of its currency), so the discount a surface shows is the
     * one that is recorded. Every caller passes that currency (`currency`: the store's for food orders, POS and the
     * marketplace bag; the platform's for plan / add-on checkout and wallet top-up codes) or the `order` (settlement).
     * Bug batch 9 · 3: with neither (only validate()'s plan-checkout fallback), the platform currency — what a plan is
     * billed in — instead of a flat 2.
     *
     * @param  array<string, mixed>  $context
     */
    private static function discountDecimals(array $context): int
    {
        $currency = $context['currency'] ?? null;
        if (($currency === null || $currency === '') && ($context['order'] ?? null) instanceof Order) {
            $currency = $context['order']->minorMoneyCurrency();
        }

        return money_decimals(($currency === null || $currency === '') ? null : (string) $currency);
    }

    /**
     * Atomically claim one use of a coupon. Returns true only when THIS caller
     * actually incremented `used` while the row was still under `limit` — the
     * grant/discount must be gated on that boolean. resolve()'s `used < limit`
     * gate is check-then-act: two concurrent 100%-off checkouts of a limit=1
     * coupon both clear it, and a plain `increment('used')` at each settlement
     * then mints two free plans from a single-use coupon. The conditional UPDATE
     * below is evaluated atomically by the DB, so exactly one of the two wins.
     *
     * A null / <= 0 limit is the "unlimited" sentinel: the WHERE always matches,
     * so the claim always succeeds (used still bumps, for accounting only).
     *
     * @param  int|Coupon  $coupon
     */
    public static function claimUsage($coupon): bool
    {
        $id = $coupon instanceof Coupon ? $coupon->id : (int) $coupon;

        return Coupon::where('id', $id)
            ->where(function ($q) {
                $q->whereNull('limit')
                    ->orWhere('limit', '<=', 0)
                    ->orWhereColumn('used', '<', 'limit');
            })
            ->increment('used') > 0;
    }

    /**
     * M3: atomically claim one PER-CUSTOMER use of a coupon.
     *
     * Returns the redemption row id on success, 0 when no per-customer gate applies
     * (no limit set, or a guest order with no customer identity), and false when
     * this customer has exhausted their allowance. Compare strictly (`=== false`):
     * 0 is a success.
     *
     * The gate is the UNIQUE (coupon_id, customer_id, seq) index, not the COUNT
     * above it — the count only picks which slot to try. Two concurrent claims both
     * compute seq = N; the database lets exactly one insert win and throws a
     * duplicate-key error at the other, which recounts and either takes N+1 or is
     * refused. Same retry shape as FiscalChain::issue()
     * (core/app/Modules/Fiscal/Services/FiscalChain.php:42-52), whose lockForUpdate cannot
     * serialize a first-of-its-kind row either.
     *
     * @param  int|Coupon  $coupon
     */
    public static function claimForCustomer($coupon, ?int $customerId, ?int $orderId = null): int|false
    {
        $couponId = $coupon instanceof Coupon ? (int) $coupon->id : (int) $coupon;
        $limit = $coupon instanceof Coupon
            ? $coupon->per_customer_limit
            : Coupon::whereKey($couponId)->value('per_customer_limit');

        // No cap configured, or a guest checkout with no customer identity to cap.
        // Guests stay ungated — unchanged behaviour, and deliberately out of scope:
        // capping by phone/email is a separate product decision, not a race fix.
        if ($limit === null || (int) $limit <= 0 || ! $customerId) {
            return 0;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $used = CouponRedemption::countFor($couponId, (int) $customerId);
            if ($used >= (int) $limit) {
                return false;
            }

            try {
                $insert = fn () => CouponRedemption::create([
                    'coupon_id' => $couponId,
                    'customer_id' => (int) $customerId,
                    'seq' => $used + 1,
                    'order_id' => $orderId,
                    'created_at' => now(),
                ]);
                // PostgreSQL: a failed insert aborts the whole open transaction (checkout runs in one) unless it
                // is rolled back to a savepoint before the recount below. MySQL/sqlite keep the transaction usable.
                $row = DB::getDriverName() === 'pgsql' ? DB::transaction($insert) : $insert();

                return (int) $row->id;
            } catch (QueryException $e) {
                if (! self::isDuplicateKey($e)) {
                    throw $e;
                }
                usleep(2000 * ($attempt + 1));
            }
        }

        return false;
    }

    /**
     * M3: hand back a per-customer slot claimed a moment ago. Used only as a
     * compensating action when the GLOBAL claimUsage() then fails — see
     * OrderPricing::applyCouponAndCredit(). Deleting our own row by primary key is
     * safe: we know we inserted it and nothing else can hold that id.
     */
    public static function releaseCustomerClaim(int|false $redemptionId): void
    {
        if (is_int($redemptionId) && $redemptionId > 0) {
            CouponRedemption::whereKey($redemptionId)->delete();
        }
    }

    /** MySQL 1062 / sqlite "UNIQUE constraint failed" / Postgres 23505. */
    private static function isDuplicateKey(QueryException $e): bool
    {
        return (string) ($e->errorInfo[1] ?? '') === '1062'
            || str_contains(strtolower($e->getMessage()), 'unique')
            || (string) ($e->errorInfo[0] ?? '') === '23505';
    }

    /**
     * Back-compat adapter for the diner path. Owner-scoped; when $includeGlobal
     * is set (the platform namespace), falls back to a platform coupon. Both go
     * through resolve() so there is one code path.
     */
    public function validate(string $code, float $subtotal, ?int $userId = null, bool $includeGlobal = false, ?int $customerId = null, ?string $currency = null, ?int $branchId = null): array
    {
        if ($userId !== null) {
            // M3: thread the diner's identity so the per-customer cap is evaluated
            // at pre-flight too (guests pass null and are unaffected). P6-311: the cart's
            // branch_id is passed when the theme posts one; absent = no branch check here.
            $ctx = ['surface' => 'food_order', 'owner_id' => $userId, 'subtotal' => $subtotal, 'customer_id' => $customerId, 'currency' => $currency];
            if ($branchId) {
                $ctx['branch_id'] = $branchId;
            } else {
                $ctx['skip_branch_check'] = true; // only this pre-flight; resolve() stays strict at order time
            }
            $result = $this->resolve($code, $ctx);
            if ($result['ok'] || ! $includeGlobal) {
                return $result;
            }

            return $this->resolve($code, ['surface' => 'plan_checkout', 'subtotal' => $subtotal, 'currency' => platform_currency()]);
        }

        // Bug batch 9 · 3: a plan is billed in the platform currency — its discount rounds there.
        return $this->resolve($code, ['surface' => 'plan_checkout', 'subtotal' => $subtotal, 'currency' => platform_currency()]);
    }
}
