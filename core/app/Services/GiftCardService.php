<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Exceptions\StoreCreditDisabledException;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\Order;
use App\Services\GoogleWallet\GoogleWalletService;
use App\Support\Money\TenderAmount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Loyalty\Jobs\SyncGoogleWalletPass;
use Juwri\Modules\Loyalty\Mail\GiftCardMail;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Contracts\StoreCredits;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\LedgerService;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\Webhooks\SendOnce;

/**
 * T2 gift cards. Purchase mirrors the wallet top-up flow: a pending card + pending
 * Transaction are created, the buyer pays through an existing gateway, and on
 * confirmation CheckoutController::updateUserPlan funds + activates the card here.
 * Value lives in the ledger (a 'gift_card' account per card) so the money invariant
 * holds: sum(ledger legs) == card balance, always.
 */
class GiftCardService
{
    /**
     * P6-214: retry policy for failed e-mail deliveries.
     *
     *   attempt 1 → +5 min
     *   attempt 2 → +10 min
     *   attempt 3 → +20 min
     *   attempt 4 → +40 min
     *   attempt 5 → +60 min, then GIVE UP (delivery_failed_at set).
     *
     * A resend (owner action) resets attempts to 0 and clears next_try_at / failed_at,
     * so the cap is per failure burst, not lifetime.
     */
    public const GIFT_CARD_MAX_DELIVERY_ATTEMPTS = 5;

    /** @var array<int,int> Attempt-index → backoff in MINUTES before the next try. */
    public const GIFT_CARD_DELIVERY_BACKOFF_MINUTES = [5, 10, 20, 40, 60];

    /** Generate an unguessable, unique card code (e.g. GC-XXXXXXXXXXXX). */
    public static function generateCode(): string
    {
        do {
            $code = 'GC-'.Str::upper(Str::random(12));
        } while (GiftCard::where('code', $code)->exists());

        return $code;
    }

    /**
     * Create a PENDING gift card for a purchase (not yet funded). The card becomes
     * spendable only when its funding Transaction confirms (activate()).
     */
    public static function createPending(int $storeId, float $amount, array $meta = []): GiftCard
    {
        return GiftCard::create([
            'store_id' => $storeId,
            'code' => self::generateCode(),
            'currency' => self::currencyFor($storeId),
            // Bug batch 4 · 1: the card currency's own decimals (KWD 5.125 stays 5.125).
            'initial_value' => Money::round($amount, Currencies::exponent(self::currencyFor($storeId))),
            'status' => 'pending',
            'purchaser_customer_id' => $meta['purchaser_customer_id'] ?? null,
            'purchaser_email' => $meta['purchaser_email'] ?? null,
            'recipient_email' => $meta['recipient_email'] ?? null,
            'recipient_phone' => $meta['recipient_phone'] ?? null,
            'message' => $meta['message'] ?? null,
            'design' => $meta['design'] ?? null,
            'scheduled_send_at' => $meta['scheduled_send_at'] ?? null,
            'expires_at' => self::computeExpiry($storeId),
        ]);
    }

    /**
     * Fund + activate a card from its confirmed funding Transaction. Idempotent: the
     * ledger post is keyed by the transaction id, so a replayed gateway callback
     * never double-funds, and an already-active card is left untouched.
     */
    public static function activate(GiftCard $card, Transaction $transaction): GiftCard
    {
        $card = DB::transaction(function () use ($card, $transaction) {
            $card = GiftCard::whereKey($card->id)->lockForUpdate()->firstOrFail();

            self::fund($card, (float) $card->initial_value, 'gc:fund:'.$transaction->id, (int) $transaction->id);

            if ($card->status !== 'active') {
                $card->status = 'active';
                $card->issued_at = $card->issued_at ?: Carbon::now();
                $card->transaction_id = $transaction->id;
                $card->save();
            }

            return $card;
        });

        self::syncGoogleWallet((int) $card->store_id, (int) $card->id);

        return $card;
    }

    /**
     * Post the funding legs into the ledger: platform clearing -amount, gift_card +amount.
     * Idempotent per (account, ref) — see LedgerService::post.
     */
    public static function fund(GiftCard $card, float $amount, string $ref, ?int $grantedBy = null): void
    {
        if ($amount <= 0) {
            return;
        }
        $account = $card->ledgerAccount();
        $clearing = app(Ledger::class)->accountFor('platform', 0, 'platform', $card->currency);

        app(Ledger::class)->post([
            ['account' => $clearing, 'amount' => -$amount, 'allow_negative' => true, 'ref' => $ref.':clr', 'granted_by' => $grantedBy],
            ['account' => $account, 'amount' => $amount, 'ref' => $ref.':card', 'granted_by' => $grantedBy],
        ], null, 'gift_card.fund');
    }

    /**
     * Start a purchase: create the pending card + its pending funding Transaction and
     * link them. The caller dispatches the chosen gateway's ::pay($transaction); on
     * confirmation StorePayments::activateGiftCardPurchase (Payments) funds + activates it.
     * Returns ['card' => GiftCard, 'transaction' => Transaction].
     */
    public static function initiate(int $storeId, float $amount, string $gatewayFolder, array $meta = []): array
    {
        $card = self::createPending($storeId, $amount, $meta);
        $ownerId = (int) Store::where('id', $storeId)->value('user_id');

        $transaction = Transaction::create([
            'product_name' => __('Gift card'),
            'product_id' => 0,
            'user_id' => $ownerId,
            'base_amount' => Money::round($amount, Currencies::exponent(self::currencyFor($storeId))),
            'amount' => Money::round($amount, Currencies::exponent(self::currencyFor($storeId))),
            'currency_code' => self::currencyFor($storeId),
            'transaction_method' => 'gift_card_purchase',
            'store_id' => $storeId, // P1-23: charged on the store's own gateway
            'transaction_gatway' => $gatewayFolder,
            'transaction_description' => __('Gift card').' '.$card->code,
            'frequency' => 'LIFETIME',
            'status' => null,
        ]);

        $card->transaction_id = $transaction->id;
        $card->save();

        return ['card' => $card, 'transaction' => $transaction];
    }

    /**
     * Redeem a gift card as tender against an order. Applies the smaller of the card
     * balance and the order's remaining amount_due. Concurrency-safe (row lock + the
     * ledger's own funds check under lock) and idempotent per order (a re-run tops up
     * to the correct total, never double-debits). Returns the amount applied this call.
     *
     * Stacking: gift card pays AFTER discounts + wallet — it reduces amount_due, never
     * the price. Tenant-scoped: the card must belong to the order's restaurant.
     */
    public static function applyToOrder(GiftCard $card, Order $order, ?float $requested = null): float
    {
        if ((int) $card->store_id !== (int) $order->store_id) {
            throw new \InvalidArgumentException(__('That gift card is not valid here.'));
        }

        $applied = DB::transaction(function () use ($card, $order, $requested) {
            $card = GiftCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Single-shot per (order, card): the ledger transfer ref below is fixed, so
            // re-applying the same card is a no-op rather than a double debit.
            if ((int) $order->gift_card_id === (int) $card->id && (float) $order->gift_card_applied > 0) {
                return 0.0;
            }
            // Degrade gracefully like the wallet tender (CustomerWalletService::applyToOrder):
            // findRedeemable's pre-check is UNLOCKED, so a card that drained, expired, or was
            // voided in the window before this locked apply must NOT throw — that exception
            // propagates out of OrderPricing (no try/catch) and rolls back the whole order
            // creation. Apply nothing instead, exactly as an emptied wallet does.
            if (! $card->isRedeemable()) {
                return 0.0;
            }
            // A gift card is stored value in its OWN currency. Redeeming it against an order in
            // a DIFFERENT currency (e.g. the store changed currency_code after the card was
            // issued) would move face value 1:1 with no FX — refuse rather than mis-apply.
            // But a NULL order currency means the store never set currency_code and trades in
            // the platform default — the same default the card was issued in — so it is NOT a
            // mismatch. Mirror the wallet tender's guard (CustomerWalletService::applyToOrder),
            // which skips on a null order currency; without this a store on the default
            // currency could never redeem a gift card at all.
            $orderCurrency = $order->currencyCode();
            if ($orderCurrency !== null && strtoupper((string) $card->currency) !== strtoupper((string) $orderCurrency)) {
                return 0.0;
            }

            // Bug batch 3 · C: integer minor units of the order currency, capped at the card balance, the request and
            // what is still due after EVERY tender (store credit and the Fusion wallet too) — never a float floor(×100).
            $currency = $order->minorMoneyCurrency();
            $limits = $requested !== null ? [$card->balance(), $requested] : [$card->balance()];
            $apply = TenderAmount::apply($currency, TenderAmount::dueMinor($order, $currency), ...$limits);
            if ($apply <= 0) {
                return 0.0;
            }

            // Ledger: move funds off the card to the merchant (owner earns on redemption).
            $ownerId = (int) Store::where('id', $order->store_id)->value('user_id');
            $merchant = app(Ledger::class)->accountFor('merchant', $ownerId, 'merchant', $card->currency);
            LedgerService::transfer(
                $card->ledgerAccount(),
                $merchant,
                $apply,
                'gc:redeem:order:'.$order->id.':card:'.$card->id,
                'gift_card.redeem'
            );

            $order->gift_card_id = $card->id;
            $order->gift_card_applied = Money::round((float) ($order->gift_card_applied ?? 0) + $apply, Currencies::exponent($currency));
            $order->save();

            return $apply;
        });

        if ($applied > 0) {
            self::syncGoogleWallet((int) $card->store_id, (int) $card->id);
        }

        return $applied;
    }

    /**
     * grocery-nits2 (REVIEW, Opus: money): give back PART of an order's gift-card tender to that card (the caller
     * lowers `gift_card_applied` by `$amount` under the order lock, in the same transaction): merchant → card, the
     * inverse of the redeem transfer, like reverseRedemption. `$ref` must be unique per return (the ledger drops a
     * repeated ref). Returns false, moving nothing, when the order has no card of its store: the caller keeps that
     * part on the order for the owner. A card that can no longer be spent (voided or expired) gets nothing back; the
     * part is paid out instead (original payment, else store credit, else logged as owed) under `$ref`, after commit.
     */
    public static function returnToCard(Order $order, float $amount, string $ref): bool
    {
        $cardId = (int) ($order->gift_card_id ?? 0);
        if ($cardId <= 0 || $amount <= 0) {
            return false;
        }
        $card = GiftCard::whereKey($cardId)->lockForUpdate()->first();
        if (! $card || (int) $card->store_id !== (int) $order->store_id) {
            return false;
        }
        if ($card->status !== 'active' || $card->isExpired()) {
            // money-tail 3: a card that can no longer be spent pays the part out like reverseRedemption (original
            // payment, else store credit, else logged as owed), after commit, once per `$ref`
            log_activity('giftcard.refund_started', $order, [
                'order_id' => $order->id, 'gift_card_id' => $card->id, 'amount' => $amount, 'reason' => 'tender_excess',
                'card_status' => $card->isExpired() ? 'expired' : $card->status,
            ], (int) $order->store_id);
            self::payOutAfterCommit((int) $order->id, (int) $card->id, $amount, $ref);

            return true;
        }
        $ownerId = (int) Store::where('id', $order->store_id)->value('user_id');
        $merchant = app(Ledger::class)->accountFor('merchant', $ownerId, 'merchant', $card->currency);
        LedgerService::transfer($merchant, $card->ledgerAccount(), $amount, $ref, 'gift_card.refund', true);

        return true;
    }

    /**
     * Re-credit a gift card when its order is reversed (owner cancel / POS void). The
     * mirror of applyToOrder: it moves the redeemed amount back from the merchant to
     * the card and clears the order's gift-card tender, so a customer who paid with a
     * card and had the order cancelled keeps their stored value instead of losing it.
     *
     * Idempotent per (order, card): the ledger ref is fixed (`gc:refund:…`) so a
     * repeated cancel/void is a ledger no-op, and gift_card_applied is zeroed under
     * lock so the amount can never be credited twice. Returns the amount re-credited.
     *
     * The merchant leg is allowed to run negative: reversing a redemption must always
     * succeed (the owner owes the value back), even if the merchant balance was swept
     * after the sale — a negative merchant balance correctly records the debt. Value
     * is never stranded. Returns 0 when the order carried no gift-card tender.
     */
    public static function reverseRedemption(Order $order): float
    {
        if ((int) ($order->gift_card_id ?? 0) <= 0 || (float) ($order->gift_card_applied ?? 0) <= 0) {
            return 0.0;
        }

        $creditedCardId = (int) ($order->gift_card_id ?? 0);
        $payout = null;
        $credited = DB::transaction(function () use ($order, &$payout) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $cardId = (int) ($order->gift_card_id ?? 0);
            $applied = Money::round((float) ($order->gift_card_applied ?? 0), Currencies::exponent($order->minorMoneyCurrency()));
            if ($cardId <= 0 || $applied <= 0) {
                return 0.0; // already reversed, or never redeemed
            }

            $card = GiftCard::whereKey($cardId)->lockForUpdate()->first();
            if (! $card) {
                return 0.0; // card gone — nothing to credit back to
            }

            // P6-338 / J50: never put value back onto a card that can no longer be spent (owner voided it, or it
            // expired). A drained-but-live card is fine: it is still spendable once the value is back on it.
            if ($card->status !== 'active' || $card->isExpired()) {
                // Claim under the order lock: zeroing the tender makes a repeated cancel/void a no-op, so the value
                // is paid out at most once. The payout itself runs after commit (a provider call must never sit
                // inside a transaction that can still roll back).
                $order->gift_card_applied = 0;
                $order->save();
                $payout = ['order_id' => (int) $order->id, 'card_id' => (int) $card->id, 'amount' => $applied];
                log_activity('giftcard.refund_started', $order, [
                    'order_id' => $order->id, 'gift_card_id' => $card->id, 'amount' => $applied, 'card_status' => $card->isExpired() ? 'expired' : $card->status,
                ], (int) $order->store_id);

                return 0.0;
            }

            // Merchant → card, the exact inverse of the redeem transfer. Fixed ref keeps
            // it idempotent against a double cancel; allowFromNegative lets it succeed
            // even if the owner already withdrew the redeemed funds.
            $ownerId = (int) Store::where('id', $order->store_id)->value('user_id');
            $merchant = app(Ledger::class)->accountFor('merchant', $ownerId, 'merchant', $card->currency);
            LedgerService::transfer(
                $merchant,
                $card->ledgerAccount(),
                $applied,
                'gc:refund:order:'.$order->id.':card:'.$card->id,
                'gift_card.refund',
                true
            );

            // Clear the tender so amount_due no longer counts it and a re-run early-returns.
            // gift_card_id is kept for the audit trail (applied = 0 marks it reversed).
            $order->gift_card_applied = 0;
            $order->save();

            return $applied;
        });

        if ($payout !== null) {
            self::payOutAfterCommit($payout['order_id'], $payout['card_id'], $payout['amount']);

            return 0.0;
        }

        // $creditedCardId is guaranteed > 0 by the early guard above.
        if ($credited > 0) {
            self::syncGoogleWallet((int) $order->store_id, $creditedCardId);
        }

        return $credited;
    }

    /**
     * Pays gift-card value off a card that can no longer be spent ({@see refundUnspendable}) after our own commit:
     * inside a caller's transaction it waits for that commit (never runs on a rollback); with no transaction open it
     * runs at once. A failure is never lost silently: the owner sees what is still owed (`giftcard.refund_due`).
     */
    private static function payOutAfterCommit(int $orderId, int $cardId, float $amount, ?string $ref = null): void
    {
        DB::afterCommit(function () use ($orderId, $cardId, $amount, $ref) {
            try {
                self::refundUnspendable($orderId, $cardId, $amount, $ref);
            } catch (\Throwable $e) {
                report($e);
                $o = Order::find($orderId);
                log_activity('giftcard.refund_due', $o, [
                    'order_id' => $orderId, 'gift_card_id' => $cardId, 'amount' => $amount,
                    'message' => ___('Refund the guest :amount by hand: the gift card cannot be used any more and the automatic refund failed.', ['amount' => $amount]),
                ], $o !== null ? (int) $o->store_id : null);
            }
        });
    }

    /**
     * P6-338 / J50 — pay back gift-card value from a reversed order when the card can no longer be spent.
     *
     *  1. Original payment method: refund the card's own purchase payment through the provider (the same
     *     Gateways::refundStorePayment path as other store payments: RefundGuard caps it at what was paid minus what
     *     was already refunded, reserves under a row lock and passes an idempotency key). Never more than the value
     *     taken from the card, and never more than is still unrefunded on the purchase — a purchase that was already
     *     refunded is not paid again.
     *  2. Store credit, only when the purchase cannot be refunded: no gateway payment (cash / issued by hand) or the
     *     provider refused it (refund window passed, ...). Credited to the order's customer in the card currency.
     *  3. Otherwise (provider refund must be finished in the dashboard, provider outcome unknown, no customer, store
     *     credit off) nothing more is sent and the owner is told what is owed in the activity log.
     *
     * Ledger: every route moves the value merchant → platform clearing once (fixed ref), the inverse of funding —
     * the owner gives the value back. Store credit is then posted clearing → customer by CustomerWalletService.
     *
     * @return array{route: string, amount: float, message: ?string}
     */
    public static function refundUnspendable(int $orderId, int $cardId, float $amount, ?string $ref = null): array
    {
        $order = Order::findOrFail($orderId);
        $card = GiftCard::findOrFail($cardId);
        $storeId = (int) $order->store_id;
        $currency = strtoupper((string) $card->currency);
        $exp = Currencies::exponent($currency);
        $amountMinor = Money::minorFromMajor($amount, $currency);
        $base = ['order_id' => $orderId, 'gift_card_id' => $cardId];
        // money-tail 3: a tender-excess payout has its own ref, so a later cancel's payout of the rest is not `already_done`
        $ref ??= 'gc:refund:order:'.$orderId.':card:'.$cardId;
        if (LedgerEntry::query()->where('ref', $ref.':out:from')->exists()) {
            return ['route' => 'already_done', 'amount' => 0.0, 'message' => null]; // paid out before: never twice
        }

        $result = function (string $route, int $minor, ?string $message) use ($order, $card, $currency, $exp, $base, $ref, $storeId) {
            $major = Money::round(Money::majorFromMinor($minor, $currency), $exp);
            if ($minor > 0 && $route !== 'already_refunded') {
                $ownerId = (int) Store::where('id', $storeId)->value('user_id');
                // Both legs may run negative: the owner gives back value they may already have withdrawn, and the
                // platform clearing account is a liability sink that is normally negative (see void()).
                app(Ledger::class)->post([
                    ['account' => app(Ledger::class)->accountFor('merchant', $ownerId, 'merchant', $card->currency), 'amount' => -$major, 'allow_negative' => true, 'ref' => $ref.':out:from'],
                    ['account' => self::clearing($card->currency), 'amount' => $major, 'allow_negative' => true, 'ref' => $ref.':out:to'],
                ], null, 'gift_card.refund_out');
            }
            log_activity('giftcard.'.$route, $order, $base + ['amount' => $major, 'currency' => $currency, 'message' => $message], $storeId);

            return ['route' => $route, 'amount' => $major, 'message' => $message];
        };

        // 1. The card's purchase payment, when it went through a payment provider.
        $tx = (int) ($card->transaction_id ?? 0) > 0
            ? Transaction::query()->where('store_id', $storeId)->whereKey($card->transaction_id)->first()
            : null;
        $gateway = $tx !== null ? strtolower(trim((string) $tx->transaction_gatway)) : '';
        $viaProvider = $tx !== null && $tx->transaction_method === 'gift_card_purchase'
            && ! in_array($gateway, ['', 'cash', 'offline', 'manual', 'bank_transfer', 'wallet'], true)
            && in_array($tx->status, [Transaction::STATUS_SUCCESS, Transaction::STATUS_CONSUMED], true);

        if ($viaProvider) {
            $paidMinor = Money::minorFromMajor((float) $tx->amount, (string) ($tx->currency_code ?: $currency));
            $refunded = RefundGuard::refundedMinor($tx);
            if ($refunded === null) {
                return $result('refund_due', $amountMinor, ___('Refund the guest :amount by hand: the gift card cannot be used any more.', ['amount' => Money::majorFromMinor($amountMinor, $currency).' '.$currency]));
            }
            $refundable = max(0, min($amountMinor, $paidMinor - $refunded));
            if ($refundable === 0) {
                // The purchase was already refunded in full: the guest has the money; paying again would double it.
                return $result('already_refunded', 0, ___('The gift card purchase was already refunded, so nothing more is paid back.'));
            }

            // money-last NIT 6: tagged with this payout's ref, so a retry reuses only this payout's unknown refund
            $sent = RefundGuard::forPurpose($ref, fn () => app(Gateways::class)->refundStorePayment($tx, Money::majorFromMinor($refundable, $currency), $currency));
            if (! empty($sent['automatic'])) {
                return $result('refunded_to_payment', $refundable, null);
            }
            if (empty($sent['refused'])) {
                // Auto refunds off / no adapter / provider outcome unknown: the original method can still be refunded
                // (or may already have been) — the owner finishes it in the provider dashboard. No store credit.
                return $result('refund_due', $refundable, (string) ($sent['message'] ?? ''));
            }
            $amountMinor = $refundable; // provider refused (e.g. refund window passed) → store credit below
        }

        // 2. Store credit for the order's customer.
        $customer = (int) ($order->customer_id ?? 0) > 0 ? Customer::find($order->customer_id) : null;
        if ($customer === null || (int) $customer->store_id !== $storeId) {
            return $result('refund_due', $amountMinor, ___('Refund the guest :amount by hand: the gift card cannot be used any more and the order has no customer for store credit.', ['amount' => Money::majorFromMinor($amountMinor, $currency).' '.$currency]));
        }
        if (CustomerWalletService::walletCurrency($customer) !== $currency) {
            return $result('refund_due', $amountMinor, ___('Refund the guest :amount by hand: the gift card cannot be used any more and store credit is in another currency.', ['amount' => Money::majorFromMinor($amountMinor, $currency).' '.$currency]));
        }
        try {
            CustomerWalletService::credit($customer, Money::majorFromMinor($amountMinor, $currency), ___('Gift card refund for order #:id', ['id' => $orderId]), $ref);
        } catch (StoreCreditDisabledException) {
            return $result('refund_due', $amountMinor, ___('Refund the guest :amount by hand: the gift card cannot be used any more and store credit is off.', ['amount' => Money::majorFromMinor($amountMinor, $currency).' '.$currency]));
        }

        return $result('refunded_to_store_credit', $amountMinor, null);
    }

    /**
     * Deliver an active card to its recipient (email, + WhatsApp when the restaurant
     * has a live channel). Idempotent via delivered_at — a re-run never re-sends.
     * Respects scheduled_send_at: a future send-date defers (the scheduled tick calls
     * this again when due). Returns true if it delivered on this call.
     *
     * P6-214: failures are NO LONGER silent. We log, count the attempt on the card,
     * back off the next try, and give up after GIFT_CARD_MAX_DELIVERY_ATTEMPTS.
     * The card's stored value is never touched by a delivery failure.
     */
    public static function deliver(GiftCard $card): bool
    {
        if ($card->status !== 'active' || $card->delivered_at !== null) {
            return false;
        }
        if ($card->scheduled_send_at !== null && $card->scheduled_send_at->isFuture()) {
            return false; // wait for the scheduled tick
        }
        // P6-214: permanently abandoned cards are not retried by the tick.
        if ($card->isDeliveryFailed()) {
            return false;
        }

        $store = Store::find($card->store_id);
        $restaurantName = $store->title ?? config('settings.site_title', '');
        $storeUrl = $store ? effective_public_url($store) : null; // R4b: verified store domain, else the reseller host
        // P6-265: the tick/job has no request locale; mail in the store's default language.
        $mailLocale = $store ? ((string) (store_options((int) $store->id)->default_language ?? '') ?: (string) config('app.locale')) : (string) config('app.locale');

        if ($card->recipient_email) {
            /* P6-158: duplicate-send marker. Claimed (autocommit) before the mail goes out, so a job killed after the
               send and before delivered_at / the outbox ack is re-run (outbox or the scheduled tick) without a
               second email. A clean failure releases the claim and is retried. */
            try {
                $sent = SendOnce::run(StoreCredits::GIFT_CARD_DELIVERY, (string) $card->id, function () use ($card, $restaurantName, $storeUrl, $mailLocale): void {
                    Mail::to($card->recipient_email)
                        ->send(new GiftCardMail($card, $restaurantName, $storeUrl, $mailLocale));
                });
            } catch (\Throwable $e) {
                // P6-214: book the failure on the card, back off the next try, give up
                // after MAX attempts. Balance is never touched by a delivery failure.
                self::recordDeliveryFailure($card, $e->getMessage());

                return false;
            }
            if (! $sent) {
                if (SendOnce::state(StoreCredits::GIFT_CARD_DELIVERY, (string) $card->id) === 'sent') {
                    $card->delivered_at = Carbon::now(); // the mail went out earlier; only the bookkeeping was lost
                    $card->save();
                }

                return false;
            }
        }

        // NOTE: optional WhatsApp delivery (when the restaurant runs a live WhatsApp
        // channel) is a documented follow-up — the WhatsApp suite send() is an
        // instance API with its own driver/session wiring; email is the primary channel.

        $card->delivered_at = Carbon::now();
        $card->save();

        return true;
    }

    /**
     * P6-214: book a delivery failure. Bumps the attempt counter, schedules the next
     * try with exponential backoff, and on the final attempt marks the card as
     * permanently undelivered (delivery_failed_at). Logs once at the give-up moment
     * so a hard-bounced address or dead SMTP is visible in the logs.
     */
    public static function recordDeliveryFailure(GiftCard $card, string $reason): void
    {
        $attempts = (int) ($card->delivery_attempts ?? 0) + 1;
        $truncated = mb_substr($reason, 0, 500);
        $backoffMinutes = self::GIFT_CARD_DELIVERY_BACKOFF_MINUTES[$attempts - 1] ?? self::GIFT_CARD_DELIVERY_BACKOFF_MINUTES[array_key_last(self::GIFT_CARD_DELIVERY_BACKOFF_MINUTES)];
        $giveUp = $attempts >= self::GIFT_CARD_MAX_DELIVERY_ATTEMPTS;

        $card->delivery_attempts = $attempts;
        $card->delivery_last_error = $truncated;
        $card->delivery_next_try_at = $giveUp ? null : Carbon::now()->addMinutes($backoffMinutes);
        $card->delivery_failed_at = $giveUp ? Carbon::now() : null;
        $card->save();

        Log::warning('gift_card.delivery_failed', [
            'gift_card_id' => $card->id,
            'store_id' => $card->store_id,
            'attempt' => $attempts,
            'max_attempts' => self::GIFT_CARD_MAX_DELIVERY_ATTEMPTS,
            'gave_up' => $giveUp,
            'next_try_at' => $giveUp ? null : $card->delivery_next_try_at?->toIso8601String(),
            'recipient' => $card->recipient_email,
            'error' => $truncated,
        ]);
    }

    /**
     * P6-214: owner-initiated resend. Resets the retry bookkeeping so a fresh
     * burst of up to GIFT_CARD_MAX_DELIVERY_ATTEMPTS tries is available, then
     * tries once immediately. Returns true if the email was queued on this call.
     */
    public static function resend(GiftCard $card): bool
    {
        $card = GiftCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
        $card->delivery_attempts = 0;
        $card->delivery_next_try_at = null;
        $card->delivery_last_error = null;
        $card->delivery_failed_at = null;
        $card->delivered_at = null; // a brand-new send restarts idempotency
        $card->save();

        return self::deliver($card->fresh());
    }

    /** Release every active, undelivered card whose scheduled send-time has arrived. */
    public static function deliverDue(): int
    {
        $count = 0;
        GiftCard::where('status', 'active')
            ->whereNull('delivered_at')
            ->whereNull('delivery_failed_at')
            ->where(function ($q) {
                $q->whereNull('scheduled_send_at')
                    ->orWhere('scheduled_send_at', '<=', Carbon::now());
            })
            // P6-214: respect the per-card backoff window. A card whose last failure
            // scheduled its next try for +20 min is hidden until that moment arrives.
            ->where(function ($q) {
                $q->whereNull('delivery_next_try_at')
                    ->orWhere('delivery_next_try_at', '<=', Carbon::now());
            })
            ->orderBy('id')
            ->chunkById(200, function ($cards) use (&$count) {
                foreach ($cards as $card) {
                    if (self::deliver($card)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Owner issues a comp/manual card (no purchase). Funds it immediately from the
     * platform clearing account and activates it. Idempotent funding ref.
     */
    public static function issueManual(int $storeId, float $amount, array $meta = []): GiftCard
    {
        $card = self::createPending($storeId, $amount, $meta);
        $card = DB::transaction(function () use ($card, $amount) {
            self::fund($card, Money::round($amount, Currencies::exponent($card->currency)), 'gc:issue:'.$card->id);
            $card->status = 'active';
            $card->issued_at = Carbon::now();
            $card->save();

            return $card;
        });

        self::syncGoogleWallet((int) $card->store_id, (int) $card->id);

        return $card;
    }

    /** Void a card (owner). Its remaining balance is written off to the platform. */
    public static function void(GiftCard $card): void
    {
        DB::transaction(function () use ($card) {
            $card = GiftCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
            $balance = $card->balance();
            if ($balance > 0) {
                // Card -balance, platform clearing +balance. The clearing account is a
                // liability sink that runs negative, so its leg always allows negative
                // (post() checks every leg, even a credit landing on a negative account).
                app(Ledger::class)->post([
                    ['account' => $card->ledgerAccount(), 'amount' => -$balance, 'ref' => 'gc:void:'.$card->id.':card'],
                    ['account' => self::clearing($card->currency), 'amount' => $balance, 'allow_negative' => true, 'ref' => 'gc:void:'.$card->id.':clr'],
                ], null, 'gift_card.void');
            }
            $card->status = 'disabled';
            $card->save();
        });

        self::syncGoogleWallet((int) $card->store_id, (int) $card->id);
    }

    /**
     * Manual balance adjustment (owner), + credit or − debit. Audited by the caller.
     *
     * B1: a double-submitted adjustment (double-click / retry) must apply ONCE. When
     * the form supplies a per-render idempotency $token, the ledger ref is keyed on it,
     * so a second POST with the SAME token is a ledger no-op (dup (account, ref)). A
     * fresh page render mints a fresh token, so two DELIBERATE adjustments still both
     * apply. Without a token the ref stays time-based (repeatable — the legacy
     * behaviour, kept for internal callers that intend distinct repeatable moves).
     */
    public static function adjust(GiftCard $card, float $delta, string $reason = 'manual', ?string $token = null): void
    {
        // Bug batch 4 · 1: a delta that rounds to zero minor units of the card currency is no adjustment.
        if (Money::minorFromMajor(abs($delta), $card->currency) === 0) {
            return;
        }
        DB::transaction(function () use ($card, $delta, $reason, $token) {
            $card = GiftCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
            if ($token !== null && $token !== '') {
                $ref = 'gc:adjust:'.$card->id.':t:'.substr(hash('sha256', $token), 0, 24);
                // Replay short-circuit BEFORE the debit funds-check: on a repeated
                // debit the balance has already dropped, so the guard below would
                // wrongly reject the (idempotent) second submit. If the card leg for
                // this ref already exists, the adjustment landed — nothing to do.
                if (LedgerEntry::where('ledger_account_id', $card->ledgerAccount()->id)
                    ->where('ref', $ref.':card')->exists()) {
                    return;
                }
            } else {
                $ref = 'gc:adjust:'.$card->id.':'.substr(md5($reason.microtime(false)), 0, 8);
            }
            $amt = abs(Money::round($delta, Currencies::exponent($card->currency)));
            // The platform clearing leg always allows negative (liability sink); the
            // card leg is funds-checked normally on a debit.
            if ($delta > 0) {
                app(Ledger::class)->post([
                    ['account' => self::clearing($card->currency), 'amount' => -$amt, 'allow_negative' => true, 'ref' => $ref.':clr'],
                    ['account' => $card->ledgerAccount(), 'amount' => $amt, 'ref' => $ref.':card'],
                ], null, 'gift_card.adjust');
            } else {
                // A debit beyond the balance would fail the ledger funds check with a raw
                // RuntimeException (an uncaught 500 for the owner). Reject it cleanly as a
                // form error instead — a card balance cannot go negative.
                if ($amt > $card->balance() + 0.0001) {
                    throw ValidationException::withMessages([
                        'delta' => ___('Cannot debit more than the card balance.'),
                    ]);
                }
                app(Ledger::class)->post([
                    ['account' => $card->ledgerAccount(), 'amount' => -$amt, 'ref' => $ref.':card'],
                    ['account' => self::clearing($card->currency), 'amount' => $amt, 'allow_negative' => true, 'ref' => $ref.':clr'],
                ], null, 'gift_card.adjust');
            }
        });

        self::syncGoogleWallet((int) $card->store_id, (int) $card->id);
    }

    private static function clearing(string $currency): LedgerAccount
    {
        return app(Ledger::class)->accountFor('platform', 0, 'platform', $currency);
    }

    /**
     * Google Wallet (google-suite Lane C): queue a gift-card pass balance sync after
     * a balance change so a customer who saved this card's pass sees the new value.
     * Dark by default — dispatches ONLY when the platform creds are present AND the
     * store toggled google_wallet on. Fully self-guarded: a wallet failure must never
     * break the gift-card money path. The job re-reads live balance (idempotent), so
     * dispatching right after the mutation is safe.
     *
     * ML-1120 review NIT 5: after the outermost commit (at once when no transaction is open). A reversal nests inside
     * OrderCancellation::reverseTenders' transaction; a later rollback there must not leave a pass showing a balance
     * that never happened.
     */
    private static function syncGoogleWallet(int $storeId, int $cardId): void
    {
        DB::afterCommit(function () use ($storeId, $cardId) {
            try {
                if (GoogleWalletService::enabledForStore($storeId)) {
                    SyncGoogleWalletPass::dispatch('giftcard', $storeId, $cardId);
                }
            } catch (\Throwable $e) {
                Log::warning('Google Wallet gift-card sync dispatch failed: '.$e->getMessage());
            }
        });
    }

    /**
     * B2: flip lapsed cards from 'active' to 'expired'. isRedeemable() already rejects
     * a past-expiry card, but the status column stays 'active', so reporting and the
     * owner admin can't SEE it as expired. This sweep makes the column honest.
     * Idempotent (only touches active cards with a past expires_at) and chunked; safe
     * to run repeatedly / on a daily schedule. Returns the number flipped.
     */
    public static function expireLapsed(): int
    {
        $count = 0;
        GiftCard::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->chunkById(500, function ($cards) use (&$count) {
                foreach ($cards as $card) {
                    $card->update(['status' => 'expired']);
                    $count++;
                }
            });

        return $count;
    }

    /** Outstanding liability = sum of REDEEMABLE active card balances for a restaurant. */
    public static function liabilityFor(int $storeId): float
    {
        $total = 0.0;
        // Exclude lapsed cards: the daily expireLapsed() sweep flips them to 'expired',
        // but this also guards the window BETWEEN sweeps — a still-'active' card past its
        // expiry is dead value isRedeemable() rejects, so counting it would overstate the
        // outstanding liability on the owner dashboard.
        GiftCard::where('store_id', $storeId)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('id')->chunkById(200, function ($cards) use (&$total) {
                foreach ($cards as $card) {
                    $total += $card->balance();
                }
            });

        return Money::round($total, Currencies::exponent(self::currencyFor($storeId)));
    }

    /** Total redeemed onto orders for a restaurant (from the tender column). */
    public static function redeemedTotalFor(int $storeId): float
    {
        return Money::round((float) Order::where('store_id', $storeId)
            ->sum(DB::raw(Order::minorMoneySql('gift_card_applied'))), Currencies::exponent(self::currencyFor($storeId)));
    }

    /** Resolve a redeemable card by code, scoped to a restaurant. Null if not usable. */
    public static function findRedeemable(string $code, int $storeId): ?GiftCard
    {
        $card = GiftCard::where('store_id', $storeId)
            ->whereRaw('UPPER(code) = ?', [Str::upper(trim($code))])
            ->first();

        return ($card && $card->isRedeemable()) ? $card : null;
    }

    // --- settings ---------------------------------------------------------

    /**
     * Can this store sell gift cards right now?
     *
     * FIX-2 F2-4 (gap audit): this is the ONE choke point behind both customer
     * entry points (the buy page GET and the purchase POST — the money path the
     * audit flagged as carrying no gate at all), so all three checks live here:
     *   1. platform kill-switch veto (previously only on the GET route),
     *   2. the store OWNER's entitlement once `gift_cards` is plan_gated
     *      (store-scoped: the buyer is a guest, never the subject),
     *   3. the per-store toggle.
     * Redemption of an ALREADY-ISSUED card is deliberately NOT gated here —
     * stranding a customer's stored value because the merchant lapsed would be
     * taking money for nothing. Only issuance/sale is gated.
     */
    public static function enabled(int $storeId): bool
    {
        if (! self::storeEntitled($storeId)) {
            return false;
        }

        return ! empty(store_options($storeId, 'gift_cards_enabled'));
    }

    /**
     * Kill veto + (once plan_gated) the store OWNER's entitlement. Shared
     * serve-time primitive — see App\Services\FeatureAccess for the subject
     * rationale and the two-lane contract.
     */
    public static function storeEntitled(int $storeId): bool
    {
        return FeatureAccess::storeAllows($storeId, 'gift_cards');
    }

    public static function currencyFor(int $storeId): string
    {
        return (string) (store_options($storeId, 'currency_code') ?: app(Ledger::class)->defaultCurrency());
    }

    /** Preset purchase amounts (owner-configured csv), sane default if unset. */
    public static function presets(int $storeId): array
    {
        $raw = trim((string) store_options($storeId, 'gift_card_presets', ''));
        $vals = $raw === ''
            ? [25, 50, 100]
            : array_values(array_filter(array_map(fn ($v) => (float) trim($v), explode(',', $raw)), fn ($v) => $v > 0));

        return $vals ?: [25, 50, 100];
    }

    public static function minAmount(int $storeId): float
    {
        return max(1.0, (float) store_options($storeId, 'gift_card_min', 5));
    }

    public static function maxAmount(int $storeId): float
    {
        return max(self::minAmount($storeId), (float) store_options($storeId, 'gift_card_max', 500));
    }

    /** Expiry per policy: months from issue (0/empty = never). */
    public static function computeExpiry(int $storeId): ?Carbon
    {
        $months = (int) store_options($storeId, 'gift_card_expiry_months', 0);

        return $months > 0 ? Carbon::now()->addMonths($months) : null;
    }
}
