<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Exceptions\StoreCreditCurrencyMismatchException;
use App\Exceptions\StoreCreditDisabledException;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Money\TenderAmount;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * Coupons & Wallets P3: customer store credit (spendable cash), a ledger account
 * of kind `customer_store_credit`. A Customer row already carries its
 * `store_id`, so the account is keyed per customer row — i.e. per
 * (customer, owner) as decision B intends, since customers are restaurant-scoped.
 *
 * This is CASH, entirely separate from loyalty POINTS (LoyaltyAccount) per D4 —
 * the two never touch each other's balances.
 */
class CustomerWalletService
{
    public static function accountFor(Customer $customer, ?string $currency = null): LedgerAccount
    {
        return app(Ledger::class)->accountFor(
            'customer',
            $customer->id,
            'customer_store_credit',
            $currency ?: static::currencyFor($customer)
        );
    }

    /**
     * The stable currency for a customer's store credit: the customer's OWN
     * restaurant currency. Store credit is restaurant-scoped, so its currency
     * must be a fixed property of the (customer, restaurant) — NOT the ambient
     * `config('settings.currency_code')`, which the storefront rewrites per
     * request. Deriving it here keeps credit-creation, display, and spend on the
     * SAME account regardless of when in the request each runs (fixes the
     * submitOrder timing bug where credit applied before the restaurant currency
     * was pushed into config, debiting the global-default account instead of the
     * one shown to the diner). Falls back to the platform default currency.
     */
    /** The customer's store-credit account currency (ISO, upper case). */
    public static function walletCurrency(Customer $customer): string
    {
        return static::currencyFor($customer);
    }

    protected static function currencyFor(Customer $customer): string
    {
        $code = $customer->store_id
            ? store_options($customer->store_id, 'currency_code')
            : null;

        return $code ? strtoupper((string) $code) : app(Ledger::class)->defaultCurrency();
    }

    public static function balanceFor(Customer $customer, ?string $currency = null): float
    {
        // P1-05 10b: the exact minor-unit balance in the account's own currency (ledger legs are ≤ 2 decimals).
        $account = static::accountFor($customer, $currency);

        return Money::round(app(Ledger::class)->accountBalance($account), Currencies::exponent($account->currency));
    }

    /**
     * P3-2: add store credit for a customer (balanced against the platform
     * clearing account). Idempotent via `$ref` — the primitive behind
     * refund-to-credit (ref like `refund:order:{id}`), admin goodwill, or a
     * promo credit. Returns the new balance. Decision C: no diner gateway top-up
     * at launch, so there is no self-service top-up path here yet.
     */
    public static function credit(Customer $customer, float $amount, string $reason, string $ref, ?int $grantedBy = null): float
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        /* MONETIZATION B10: gate ISSUANCE — creating or topping up store credit —
           behind `customer_wallet`. Redemption (debit / applyToOrder) is
           deliberately NOT gated: money a guest already loaded stays spendable
           forever (DECISIONS non-gate). The subject is the STORE OWNER, so this
           uses storeAllows, not `feature:` middleware — the storefront/refund
           callers are often anonymous or staff, never the buyer.

           Lands DARK: while customer_wallet is plan_gated=0, storeAllows returns
           true and nothing changes. At V0 a non-entitled store can no longer
           ISSUE new credit; callers already treat a throw here as "not
           available" and fall back (a gateway refund instead of refund-to-credit). */
        if (! FeatureAccess::storeAllows($customer->store_id, 'customer_wallet')) {
            throw new StoreCreditDisabledException('Customer store credit is not enabled for this store.');
        }

        $account = static::accountFor($customer);
        $clearing = app(Ledger::class)->accountFor('platform', 0, 'platform', $account->currency);

        app(Ledger::class)->post([
            ['account' => $clearing, 'amount' => -$amount, 'allow_negative' => true, 'ref' => 'cw:'.$ref.':clr', 'reason' => $reason, 'granted_by' => $grantedBy],
            ['account' => $account, 'amount' => $amount, 'ref' => 'cw:'.$ref.':cust', 'reason' => $reason, 'granted_by' => $grantedBy, 'meta' => ['customer_id' => $customer->id, 'store_id' => $customer->store_id]],
        ], null, $reason);

        return static::balanceFor($customer);
    }

    /**
     * Self-service gateway top-up (supersedes the "no top-up at launch" note
     * above). Creates the pending Transaction for `$amount` that the storefront
     * routes through an installed payment gateway (the SAME machinery gift-card
     * purchases use). On a confirmed charge, CheckoutController::updateUserPlan
     * calls creditTopupFromTransaction() below — never the redirect return — so a
     * customer only gets balance for money the gateway actually captured.
     *
     * Feature-gating is inherited: credit() throws unless the store is entitled
     * to `customer_wallet`, so a non-entitled store can create the intent but the
     * confirm branch refuses to credit (and the UI should not surface top-up at
     * all — storeAllows is the gate to check when rendering).
     *
     * @return array{transaction: Transaction}
     */
    public static function initiateTopup(int $storeId, float $amount, string $gatewayFolder, int $customerId): array
    {
        $currency = store_options($storeId, 'currency_code');
        $currency = $currency ? strtoupper((string) $currency) : app(Ledger::class)->defaultCurrency();
        // Bug batch 4 · 1: in the store currency's own decimals (a KWD 1.125 top-up is 1.125, not 1.13).
        $amount = Money::round($amount, Currencies::exponent($currency));
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Top-up amount must be positive.');
        }

        $ownerId = (int) Store::where('id', $storeId)->value('user_id');

        $transaction = Transaction::create([
            'product_name' => ___('Wallet top-up'),
            // product_id carries the STORE id (gift cards use 0); the customer id
            // rides in details so the confirm branch can resolve both.
            'product_id' => $storeId,
            'user_id' => $ownerId,
            'base_amount' => $amount,
            'amount' => $amount,
            'currency_code' => $currency,
            // NB: transaction_method is varchar(20) — keep this key <= 20 chars.
            'transaction_method' => 'cust_wallet_topup',
            'store_id' => $storeId, // P1-23: charged on the store's own gateway
            'transaction_gatway' => $gatewayFolder,
            'transaction_description' => ___('Wallet top-up'),
            'frequency' => 'LIFETIME',
            'status' => null,
            'details' => ['customer_id' => $customerId, 'store_id' => $storeId],
        ]);

        return ['transaction' => $transaction];
    }

    /**
     * Confirm branch for a cust_wallet_topup transaction, invoked from
     * CheckoutController::updateUserPlan. That choke point already claims
     * `credited_at` atomically before dispatching here, so a replayed callback
     * never re-enters; credit()'s own `$ref` (topup:tx:{id}) is a second guard.
     * Deliberately does NOT re-claim credited_at (updateUserPlan already did —
     * re-claiming here would always fail and silently skip the credit).
     */
    public static function creditTopupFromTransaction(Transaction $transaction): void
    {
        $customerId = (int) ($transaction->details->customer_id ?? 0);
        $customer = $customerId ? Customer::find($customerId) : null;
        if (! $customer) {
            report(new \RuntimeException('customer_wallet_topup tx '.$transaction->id.' has no resolvable customer'));

            return;
        }

        static::credit(
            $customer,
            (float) $transaction->amount,
            ___('Wallet top-up'),
            'topup:tx:'.$transaction->id
        );
    }

    /**
     * P3-2: refund an amount to a customer's store credit, idempotent per order — or per refund when the caller passes
     * a `$refundKey` (the id of the refund row it created), so several partial refunds of one order each credit and
     * each reach the books.
     * Wired to the owner/admin refund action in P3-5.
     *
     * `$currency` is the refund's own currency (the order's). When given it must
     * equal the customer's store-credit currency: credit is never issued 1:1 in
     * another currency (same rule as applyToOrder) — throws
     * StoreCreditCurrencyMismatchException and credits nothing.
     */
    public static function refundToCredit(Customer $customer, float $amount, int $orderId, ?int $grantedBy = null, ?string $currency = null, ?string $refundKey = null): float
    {
        if ($currency !== null && strtoupper($currency) !== static::currencyFor($customer)) {
            throw new StoreCreditCurrencyMismatchException('Refund currency '.strtoupper($currency).' differs from the store credit currency '.static::currencyFor($customer).'.');
        }

        $balance = static::credit($customer, $amount, ___('Refund for order #:id', ['id' => $orderId]), 'refund:order:'.$orderId.($refundKey !== null ? ':'.$refundKey : ''), $grantedBy);

        // SYSTEMS-RUN A-P1: classify the refund into the GL (no-op unless
        // accounting_gl is on; idempotent per order). Reporting only.
        try {
            $order = Order::find($orderId);
            if ($order) {
                app(GeneralLedger::class)->postRefund($order, $amount, $refundKey ?? 'order:'.$orderId);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $balance;
    }

    /**
     * P3-3: debit a customer's store credit. Funds-gated (throws if short),
     * balanced against the platform clearing account, idempotent via `$ref`.
     */
    public static function debit(Customer $customer, float $amount, string $reason, string $ref): float
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive.');
        }

        $account = static::accountFor($customer);
        $clearing = app(Ledger::class)->accountFor('platform', 0, 'platform', $account->currency);

        app(Ledger::class)->post([
            ['account' => $account, 'amount' => -$amount, 'ref' => 'cw:'.$ref.':cust', 'reason' => $reason],
            ['account' => $clearing, 'amount' => $amount, 'allow_negative' => true, 'ref' => 'cw:'.$ref.':clr', 'reason' => $reason],
        ], null, $reason);

        return static::balanceFor($customer);
    }

    /**
     * P3-3: apply store credit to a food order, server-side. The applied amount
     * is capped at the balance AND the order's outstanding amount (never
     * client-priced), floored to the cent. Records `orders.wallet_applied` and
     * debits the ledger. Idempotent per order — a retry re-returns the already
     * applied amount without re-debiting or double-counting.
     */
    public static function applyToOrder(Customer $customer, Order $order, float $requested): float
    {
        // Tenant fence: store credit is restaurant-scoped (the account is keyed per
        // customer row, and a Customer belongs to exactly one restaurant), so it may
        // only tender against an order at that SAME restaurant. Without this, a
        // staff/owner of store A could pass another store's customer_id together with
        // use_wallet at order intake (customer_id is not tenant-validated there) and
        // drain that foreign customer's balance against a store-A order. Refuse
        // cross-tenant, the same way the currency guard below refuses cross-currency.
        // The gift-card tender is already fenced this way (findRedeemable by restaurant).
        if ((int) $customer->store_id !== (int) $order->store_id) {
            return 0.0;
        }

        $ref = 'order-credit:'.$order->id;
        $account = static::accountFor($customer);

        // Already applied? (idempotent — recomputing would see a drained balance)
        if (LedgerEntry::where('ledger_account_id', $account->id)->where('ref', 'cw:'.$ref.':cust')->exists()) {
            return (float) ($order->wallet_applied ?? 0);
        }

        // Symmetric with the gift-card tender (GiftCardService::applyToOrder): store credit
        // is denominated in the customer's restaurant currency, so applying it to an order
        // stamped in a DIFFERENT currency would move value 1:1 with no FX. Refuse rather than
        // mis-apply. A normal order (currency_code null → the restaurant currency) matches and
        // is unaffected; only an explicitly cross-currency order is blocked.
        $orderCurrency = $order->currencyCode();
        if ($orderCurrency !== null && strtoupper((string) $orderCurrency) !== static::currencyFor($customer)) {
            return 0.0;
        }

        // Bug batch 3 · C: integer minor units of the order currency, capped at the balance, the request and what is
        // still due after EVERY tender (the gift card and the Fusion wallet too) — never a float floor(×100).
        $currency = $order->minorMoneyCurrency();
        $applied = TenderAmount::apply($currency, TenderAmount::dueMinor($order, $currency), $requested, static::balanceFor($customer));
        if ($applied <= 0) {
            return 0.0;
        }

        try {
            static::debit($customer, $applied, ___('Store credit on order #:id', ['id' => $order->id]), $ref);
        } catch (\RuntimeException $e) {
            // balanceFor() above is an UNLOCKED read. A sibling order that spent the
            // credit in the window before this debit makes debit()'s locked ledger
            // check throw "Insufficient balance". That RuntimeException would propagate
            // out of OrderPricing::applyCouponAndCredit (no try/catch) and roll back the
            // whole order creation — a 500 for a race that lost the customer nothing.
            // Apply nothing instead, exactly as the gift-card tender degrades
            // (GiftCardService::applyToOrder) and as this method's own emptied-wallet
            // path does. The customer's balance is untouched and can be applied later.
            return 0.0;
        }
        $order->wallet_applied = Money::round((float) ($order->wallet_applied ?? 0) + $applied, Currencies::exponent($currency));
        $order->save();

        return $applied;
    }

    /**
     * Reverse the store-credit tender when an order is cancelled/voided — the inverse
     * of applyToOrder. Returns the wallet_applied amount to the customer's balance and
     * clears the order's tender, so a diner who paid with store credit keeps that value
     * when the order is reversed instead of losing it with the order.
     *
     * Posts the ledger legs DIRECTLY, NOT via credit(): credit() gates on the
     * customer_wallet ISSUANCE entitlement and would throw on a downgraded store —
     * but returning money the customer already spent must never be blocked. Idempotent
     * per order via a fixed `cw:refund:order:{id}` ref (distinct from applyToOrder's
     * `cw:order-credit:{id}`) plus the tender zeroed under lock, so a repeated cancel is
     * a no-op. The platform clearing leg absorbs the credit (allow_negative). Returns
     * the amount re-credited; 0 when the order carried no store-credit tender.
     */
    public static function reverseForOrder(Order $order): float
    {
        if ((int) ($order->customer_id ?? 0) <= 0 || (float) ($order->wallet_applied ?? 0) <= 0) {
            return 0.0;
        }

        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            // Bug batch 4 · 1: the exact tender in the order currency (a 3.375 KWD tender re-credits 3.375, not 3.38).
            $applied = Money::round((float) ($order->wallet_applied ?? 0), Currencies::exponent($order->minorMoneyCurrency()));
            $customerId = (int) ($order->customer_id ?? 0);
            if ($applied <= 0 || $customerId <= 0) {
                return 0.0; // already reversed, or never applied
            }

            $customer = Customer::find($customerId);
            if (! $customer) {
                return 0.0; // customer gone — nothing to credit back to
            }

            $account = static::accountFor($customer);
            $clearing = app(Ledger::class)->accountFor('platform', 0, 'platform', $account->currency);
            $reason = ___('Refund for order #:id', ['id' => $order->id]);
            app(Ledger::class)->post([
                ['account' => $clearing, 'amount' => -$applied, 'allow_negative' => true, 'ref' => 'cw:refund:order:'.$order->id.':clr', 'reason' => $reason],
                ['account' => $account, 'amount' => $applied, 'ref' => 'cw:refund:order:'.$order->id.':cust', 'reason' => $reason, 'meta' => ['customer_id' => $customer->id, 'store_id' => $customer->store_id]],
            ], null, $reason);

            // Clear the tender so amount_due no longer counts it and a re-run early-returns.
            $order->wallet_applied = 0;
            $order->save();

            return $applied;
        });
    }
}
