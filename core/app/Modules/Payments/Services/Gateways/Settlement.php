<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\OrderPaymentHandler;
use Juwri\Modules\Payments\Contracts\Outbox;
use Juwri\Modules\Payments\Data\OrderPayLink;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayRounding;
use Juwri\Modules\Payments\Services\StorePayments;
use Juwri\Modules\Payments\Support\AccountPages;
use Juwri\Modules\Payments\Support\Fulfilment;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * What every adapter does once a provider has confirmed a payment, and the
 * answers the buyer's browser gets on the way back.
 *
 * A pending transaction has `status` NULL. Store orders (`transaction_method`
 * = `order`, `store_id` = the store, `product_id` = the order) settle through
 * PaymentController::paySuccess; everything else (plans, add-ons, wallet
 * top-ups, deposits, gift cards, ...) through CheckoutController::updateUserPlan,
 * which routes on the method. Both claim the transaction atomically, so a
 * replayed or concurrent confirmation credits nothing twice.
 */
final class Settlement
{
    public static function isOrder(Transaction $transaction): bool
    {
        return $transaction->transaction_method === 'order';
    }

    /**
     * P1-23: a payment a store's own CUSTOMER makes to the store that is not an order — a gift card, a store-credit
     * top-up, a reservation or catering deposit ({@see StorePayments::METHODS}) — carrying the store in `store_id`.
     */
    public static function isStorePayment(Transaction $transaction): bool
    {
        return in_array($transaction->transaction_method, StorePayments::METHODS, true) && (int) $transaction->store_id > 0;
    }

    /**
     * P1-23 ("customer money goes straight to the store's own gateway; juwri never holds it", Terms 4.3): orders and
     * store payments are charged, verified and refunded with the STORE's gateway credentials in the store's currency,
     * never the platform's. Settlement routing still follows isOrder() (orders → paySuccess, the rest → fulfil()).
     */
    public static function usesStoreGateway(Transaction $transaction): bool
    {
        return self::isOrder($transaction) || self::isStorePayment($transaction);
    }

    /** The store a payment is made to (its `store_id`): orders and store payments; null for platform purchases. */
    public static function store(Transaction $transaction): ?Store
    {
        return self::usesStoreGateway($transaction) ? Store::find($transaction->store_id) : null;
    }

    /**
     * A still-pending transaction matching the given columns.
     *
     * @param  array<string, mixed>  $where
     */
    public static function pending(array $where, bool $withoutPaymentId = false): ?Transaction
    {
        $query = Transaction::query()->whereNull('status');
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }
        if ($withoutPaymentId) {
            $query->whereNull('payment_id');
        }

        return $query->first();
    }

    /** Record the provider's confirmation on the transaction (`transaction_gatway` is the column's real name). */
    public static function markPaid(Transaction $transaction, string $gateway, ?string $paymentId = null): void
    {
        $changes = ['transaction_gatway' => $gateway, 'status' => Transaction::STATUS_SUCCESS];
        if ($paymentId !== null) {
            $changes['payment_id'] = $paymentId;
        }
        $transaction->update($changes);
        GatewayRounding::book($transaction, $gateway); // Stripe/Razorpay 3-decimal round-down: book the difference
    }

    /**
     * markPaid() as one conditional UPDATE: true only for the caller that moved
     * the row out of pending (a return racing a webhook settles once).
     */
    public static function claimPaid(Transaction $transaction, string $gateway, ?string $paymentId = null): bool
    {
        $changes = ['transaction_gatway' => $gateway, 'status' => Transaction::STATUS_SUCCESS];
        if ($paymentId !== null) {
            $changes['payment_id'] = $paymentId;
        }
        $claimed = Transaction::query()->whereKey($transaction->getKey())->whereNull('status')->update($changes) === 1;
        if ($claimed) {
            $transaction->refresh();
            GatewayRounding::book($transaction, $gateway);
        }

        return $claimed;
    }

    /**
     * P3-09 (basket §9): the provider confirmed a CARD HOLD (authorised, not captured) of a store order. The pending
     * transaction is claimed `authorised` (one conditional UPDATE: a return racing a webhook records it once) with the
     * provider's reference and, when the provider gives one, the time the authorisation lapses; the order side
     * (OrderPaymentHandler::authorised) then places the order for picking. Nothing is credited: the final amount is
     * captured after weighing (CardHolds::capture) and settles like any payment. False when another confirmation won.
     */
    public static function authorise(Transaction $transaction, string $gateway, ?string $paymentId = null, ?\DateTimeInterface $expiresAt = null): bool
    {
        if (! self::isOrder($transaction)) {
            return false;
        }
        $changes = ['transaction_gatway' => $gateway, 'status' => CardHolds::STATUS_AUTHORISED];
        if ($paymentId !== null) {
            $changes['payment_id'] = $paymentId;
        }
        $claimed = Transaction::query()->whereKey($transaction->getKey())->whereNull('status')->update($changes) === 1;
        if (! $claimed) {
            return false;
        }
        $transaction->refresh();
        $details = (array) ($transaction->details ?? []);
        $details['authorised_minor'] = (int) $transaction->amount_minor;
        if ($expiresAt !== null) {
            $details['authorisation_expires_at'] = CarbonImmutable::instance($expiresAt)->utc()->toIso8601String();
        }
        $transaction->forceFill(['details' => $details])->save();
        app(OrderPaymentHandler::class)->authorised($transaction);

        return true;
    }

    /** Outbox message type of a confirmed payment's credit (J25-7 part 2). */
    public const CREDIT = 'payments.credit';

    /**
     * A webhook-confirmed payment: mark it paid and record its credit as an outbox message in ONE DB transaction,
     * then credit it right away through the outbox. If the credit fails it stays pending and `payments:outbox-relay`
     * retries it — before, a credit that threw after markPaid was only logged (the transaction was no longer pending,
     * so no retry could ever settle it). Exactly once still rests on the downstream claims (paySuccess consumed /
     * updateUserPlan credited_at), and each relay attempt runs in its own DB transaction, so a failing attempt also
     * rolls its claim back and the retry can claim again.
     *
     * $claim: settle only a still-pending row (Settlement::claimPaid); false when another confirmation won.
     * Throws when the inline credit did not complete, so the caller still logs/alerts and answers as before (the
     * message is kept and retried).
     */
    public static function settle(Transaction $transaction, string $gateway, ?string $paymentId = null, bool $claim = false): bool
    {
        return self::settleClaimed($transaction, function () use ($transaction, $gateway, $paymentId, $claim): bool {
            if ($claim) {
                return self::claimPaid($transaction, $gateway, $paymentId);
            }
            self::markPaid($transaction, $gateway, $paymentId);

            return true;
        });
    }

    /**
     * {@see self::settle()} with the gateway's own claim: $claim (true = this caller moved the row to paid) runs in the
     * same DB transaction as the credit message.
     *
     * @param  \Closure(): bool  $claim
     */
    public static function settleClaimed(Transaction $transaction, \Closure $claim): bool
    {
        $outbox = app(Outbox::class);
        $messageId = null;
        $settled = DB::transaction(function () use ($transaction, $claim, $outbox, &$messageId) {
            if (! $claim()) {
                return false;
            }
            $messageId = $outbox->record(self::CREDIT, ['transaction_id' => (int) $transaction->getKey()], self::CREDIT.':'.$transaction->getKey());

            return true;
        });
        if (! $settled || $messageId === null) {
            return $settled;
        }

        $outbox->relay([$messageId]);
        if ($outbox->status($messageId) !== 'done') {
            throw new RuntimeException('credit pending retry: '.($outbox->lastError($messageId) ?? 'not performed'));
        }

        return true;
    }

    /**
     * The outbox handler of {@see self::CREDIT}: idempotent — the downstream claims make a repeat a no-op.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function creditFromOutbox(array $payload): void
    {
        $transaction = Transaction::find((int) ($payload['transaction_id'] ?? 0));
        if ($transaction !== null) {
            self::credit($transaction);
        }
    }

    /**
     * The buyer's browser came back with a confirmed payment: {@see self::settle()} (durable credit), then the same
     * answer completeForBuyer() gave — the order hand-off page, or a success notice + redirect. Null when $claim and
     * another confirmation already settled it (the caller answers as for "already handled").
     */
    public static function settleForBuyer(Transaction $transaction, string $gateway, ?string $paymentId = null, string $routeAfter = 'subscription', bool $claim = false): ?Response
    {
        if (! self::settle($transaction, $gateway, $paymentId, $claim)) {
            return null;
        }
        if (self::isOrder($transaction)) {
            return response(app(OrderPaymentHandler::class)->buyerPage($transaction));
        }
        app_alert_success(___('Payment successful'));
        if ($routeAfter === 'subscription' && $transaction->transaction_method === 'credit_pack') {
            $routeAfter = 'credits.index'; // credit packs: every gateway brings the buyer back to their credits
        }

        return redirect()->to(self::buyerHome($transaction) ?? AccountPages::url($routeAfter));
    }

    /** P1-23: a store's customer goes back to that store, never to the platform account pages. */
    private static function buyerHome(Transaction $transaction): ?string
    {
        $store = self::isStorePayment($transaction) ? Store::find($transaction->store_id) : null;

        return $store !== null ? (self::storeReturnUrl($transaction, $store) ?? route('publicView', $store->slug)) : null;
    }

    /**
     * P3-10: a store payment that has its own page to come back to (a booking deposit: the booking page) carries it in
     * `details.return_url`, set server-side when the charge is opened. Only a URL on this app under the store's own
     * path is followed (never an outside host), else the store page.
     */
    private static function storeReturnUrl(Transaction $transaction, Store $store): ?string
    {
        // P3-06b phase D: a headless client's own page (`details.client_return_url`), set server-side only after the
        // storefront API checked its origin against the token's allowed origins — as the order hand-off does.
        $client = is_object($transaction->details) ? (string) ($transaction->details->client_return_url ?? '') : '';
        if (preg_match('#^https?://#i', $client) === 1) {
            return $client;
        }
        $url = is_object($transaction->details) ? (string) ($transaction->details->return_url ?? '') : '';
        $storeHome = route('publicView', $store->slug);

        return $url !== '' && str_starts_with($url, rtrim($storeHome, '/').'/') ? $url : null;
    }

    /** Credit the payment without answering a browser (webhooks). */
    public static function credit(Transaction $transaction): void
    {
        if (self::isOrder($transaction)) {
            self::captureOutput(fn () => Fulfilment::orderPaid($transaction));

            return;
        }
        Fulfilment::fulfil($transaction);
    }

    /**
     * Credit the payment and answer the returning buyer. Orders get the page
     * paySuccess prints (it opens the WhatsApp hand-off and sends the diner to
     * order tracking); everything else a success notice and a redirect.
     */
    public static function completeForBuyer(Transaction $transaction, string $routeAfter = 'subscription'): Response
    {
        if (self::isOrder($transaction)) {
            return response(self::captureOutput(fn () => Fulfilment::orderPaid($transaction)));
        }

        Fulfilment::fulfil($transaction);
        app_alert_success(___('Payment successful'));

        return redirect()->to(self::buyerHome($transaction) ?? AccountPages::url($routeAfter));
    }

    /** No pending transaction for what came back (unknown, or already settled). */
    public static function invalid(): RedirectResponse
    {
        app_alert_error(___('Invalid transaction, please try again.'));

        return redirect()->to(AccountPages::url());
    }

    /**
     * P1-23 part 5: the display-only return of a gateway whose webhook settles (Paymob, Tabby, Tamara, Geidea,
     * Paylink, Noon): "paid" or "we are confirming", then back to the store a payment was made to (orders AND store
     * payments), else the platform account pages. Moved from those controllers (they sent only orders to the store).
     */
    public static function confirmingForBuyer(?Transaction $transaction, bool $paid = false): RedirectResponse
    {
        if ($paid || ($transaction !== null && $transaction->status === Transaction::STATUS_SUCCESS)) {
            app_alert_success(___('Payment successful'));
        } else {
            app_alert_info(___('We are confirming your payment. It will update shortly.'));
        }
        $store = $transaction !== null ? self::store($transaction) : null;

        return redirect($store !== null ? route('publicView', $store->slug) : AccountPages::url());
    }

    /**
     * The answer of an adapter whose gateway has no refund API wired (the hand-written controllers it replaced had
     * none): nothing is sent to the provider; the caller records a tracked manual refund.
     *
     * @return array{ok:bool, reference:?string, message:?string}
     */
    public static function noAutomaticRefund(): array
    {
        return ['ok' => false, 'reference' => null, 'message' => ___('Could not refund automatically — complete this refund in your payment provider.')];
    }

    public static function failed(?string $reason = null): RedirectResponse
    {
        app_alert_error($reason === null || $reason === ''
            ? ___('Payment failed, please try again.')
            : ___('Payment failed').' : '.$reason);

        return back();
    }

    /** Where a buyer who abandons the provider page goes back to. */
    public static function cancelUrl(Transaction $transaction): string
    {
        $store = self::store($transaction);
        if ($store !== null) {
            return (self::isStorePayment($transaction) ? self::storeReturnUrl($transaction, $store) : null) ?? route('publicView', $store->slug);
        }

        return match ($transaction->transaction_method) {
            'addon' => AccountPages::url('addons.index'),
            'wallet_topup' => AccountPages::url('wallet.index'),
            'credit_pack' => AccountPages::url('credits.index'), // credit packs (PRICING.md §8)
            default => AccountPages::url(),
        };
    }

    /**
     * P1-23 part 6: where a buyer whose payment could not start picks another method — an order's pay page, else where
     * the buyer came from ({@see cancelUrl()}: a store payment's store, a platform purchase's account page).
     */
    public static function retryUrl(Transaction $transaction): string
    {
        return self::isOrder($transaction) ? self::orderPageUrl($transaction) : self::cancelUrl($transaction);
    }

    /** P6-09 V-15: a phone-agent order's pay page is reachable only through a temporary signed URL. */
    public static function isPhoneOrder(Transaction $transaction): bool
    {
        return OrderPayLink::isPhoneOrder($transaction);
    }

    /** The order pay page URL — see {@see \Juwri\Modules\Payments\Data\OrderPayLink::url()}. */
    public static function orderPageUrl(Transaction $transaction): string
    {
        return OrderPayLink::url($transaction);
    }

    /** "Payment for X" (orders, add-ons) or "Payment for X Plan (MONTHLY)". */
    public static function title(Transaction $transaction): string
    {
        if (self::usesStoreGateway($transaction) || $transaction->transaction_method === 'addon') {
            return 'Payment for '.$transaction->product_name;
        }

        return 'Payment for '.$transaction->product_name.' Plan ('.$transaction->frequency.')';
    }

    /**
     * The currency a transaction is charged in: its OWN stamped `currency_code` (the store's currency when an order
     * payment was created, the platform's for plans/add-ons/top-ups). A store or platform currency changed after the
     * checkout was created must not re-read the stored number in the new currency (a USD 12.50 checkout sent as
     * KWD 12.500). Rows without a stamp (pre-P1-05 legacy) keep the old source: {@see accountCurrency()}.
     */
    public static function currency(Transaction $transaction, string $fallback = 'USD'): string
    {
        $own = strtoupper(trim((string) ($transaction->getAttributes()['currency_code'] ?? '')));

        return $own !== '' ? $own : self::accountCurrency($transaction, $fallback);
    }

    /**
     * The store's (orders) or platform's CURRENT currency — what the gateway account is set up for. Used only to
     * detect a currency that moved since the checkout was created; never to charge.
     */
    public static function accountCurrency(Transaction $transaction, string $fallback = 'USD'): string
    {
        $currency = self::usesStoreGateway($transaction)
            ? store_options($transaction->store_id, 'currency_code')
            : platform_currency(); // bug batch 10 · 1: not a display swap

        return strtoupper(trim((string) ($currency ?: ($transaction->currency_code ?: $fallback))));
    }

    /**
     * Payment pages show the amount in the transaction's own currency: the store's sign/position when that is still
     * the store's (platform's) currency, else the ISO code as the sign.
     */
    public static function useStoreCurrency(Transaction $transaction): void
    {
        $currency = self::currency($transaction, (string) (config('settings.currency_code') ?: 'USD'));
        $options = self::usesStoreGateway($transaction) ? store_options($transaction->store_id) : null;
        $sign = $options !== null ? ($options->currency_sign ?? config('settings.currency_sign')) : config('settings.currency_sign');
        $pos = $options !== null ? ($options->currency_pos ?? config('settings.currency_pos')) : config('settings.currency_pos');
        if ($currency !== self::accountCurrency($transaction, $currency)) {
            $sign = $currency;
        }
        remember_platform_currency(); // bug batch 10 · 1
        config([
            'settings.currency_code' => $currency,
            'settings.currency_sign' => $sign,
            'settings.currency_pos' => $pos,
        ]);
    }

    /**
     * A sandbox/test switch as stored by any of the settings screens: the
     * platform forms save `1`, `test` or `TEST`; the store forms `Yes`/`No`.
     */
    public static function isSandbox(mixed $value): bool
    {
        // WI-06e: the shared normaliser; a missing or unknown value is live here, as before.
        return self::sandboxFlag($value, false);
    }

    /**
     * WI-06e: the one normaliser for every gateway sandbox flag (also behind
     * gateway_is_sandbox()). A `(bool)` cast reads the string 'No' as sandbox
     * ON and a `=== 'Yes'` test reads '1' as sandbox OFF. Explicit on values
     * (yes/1/true/on/test/sandbox) select sandbox, explicit off values
     * (no/0/false/off/live/production) select live; a missing, blank or
     * unknown value gets $default.
     */
    public static function sandboxFlag(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        $v = strtolower(trim(is_scalar($value) ? (string) $value : ''));
        if (in_array($v, ['yes', '1', 'true', 'on', 'test', 'sandbox'], true)) {
            return true;
        }
        if (in_array($v, ['no', '0', 'false', 'off', 'live', 'production'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * What the provider says was paid is EXACTLY our amount, at the gateway's decimals (GatewayMinorUnits::paidMatches),
     * in our currency. A mismatch is logged and refuses the settlement. Gateway exact-amount fix: before, anything
     * within ±0.01 settled — a KWD payment 9 fils short, and (float) a USD/EUR payment a whole cent short.
     */
    public static function amountMatches(Transaction $transaction, int|float|string $paid, ?string $paidCurrency, string $gateway, ?string $expectedCurrency = null): bool
    {
        $expected = strtoupper((string) ($expectedCurrency ?? $transaction->currency_code ?? ''));
        $actual = strtoupper((string) $paidCurrency);
        if (! GatewayMinorUnits::paidMatches($gateway, $paid, $transaction->money('amount'), $expected !== '' ? $expected : null)
            || ($expected !== '' && $actual !== '' && $actual !== $expected)) {
            Log::warning("{$gateway} amount/currency mismatch on transaction {$transaction->id}: paid {$paid} {$actual} expected {$transaction->amount} {$expected}");

            return false;
        }

        return true;
    }

    /**
     * The buyer is back but the provider has not confirmed yet (the webhook
     * will settle). Orders go back to the store page (opening the WhatsApp
     * hand-off when there is one), everything else to the subscription page.
     */
    public static function processingForBuyer(Transaction $transaction): Response
    {
        $store = self::store($transaction);
        if ($store === null) {
            app_alert_success(___('Your payment is processing.'));

            return redirect()->to(AccountPages::url());
        }

        $target = route('publicView', $store->slug).'?return=success';
        $whatsapp = (string) ($transaction->details->whatsapp_url ?? '');
        if (! preg_match('#^https?://#i', $whatsapp)) {
            return redirect()->to($target);
        }

        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $script = 'window.open('.json_encode($whatsapp, $flags).', "_blank");'
            .'location.href = '.json_encode($target, $flags).';';

        return response('<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.e(___('Redirecting...')).'</title></head><body>'
            .'<p><a href="'.e($target).'">'.e(___('Continue')).'</a></p>'
            .'<script nonce="'.e(function_exists('csp_nonce') ? (string) csp_nonce() : '').'">'.$script.'</script></body></html>');
    }

    private static function captureOutput(callable $callback): string
    {
        ob_start();
        try {
            $callback();
        } finally {
            $printed = (string) ob_get_clean();
        }

        return $printed;
    }
}
