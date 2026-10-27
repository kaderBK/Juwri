<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\AccountPages;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * #65 Flutterwave (flutterwave.com) — Africa (NG/GH/KE/…). P1-23 part 6: moved from the hand-written
 * FlutterwaveController into the adapter family, same wire protocol — the routes, `redirect_url` = `ipn/flutterwave`,
 * the webhook `webhook/flutterwave` and our id as `tx_ref` are unchanged, so a payment opened by the old controller still
 * settles here.
 *
 * initiate: POST /v3/payments (MAJOR units at the currency's own decimals) → the hosted link.
 * Return and webhook name a Flutterwave transaction id + our tx_ref; nothing in them is trusted. The pending transaction
 * tx_ref names is loaded first and its account's secret key — the STORE's for orders and store payments (gift cards,
 * top-ups, deposits; {@see Settlement::usesStoreGateway()}), the platform's for plans/add-ons; no key = refused — reads
 * GET /v3/transactions/{id}/verify: its own tx_ref must be ours, `successful`, the exact amount, the currency charged.
 * Settles once (WebhookReceipts + claim). The webhook also needs the dashboard `verif-hash` (fails closed without one).
 * No refund API wired (the old controller had none): tracked manual refund.
 */
class FlutterwaveAdapter implements GatewayAdapter
{
    private const BASE = 'https://api.flutterwave.com/v3';

    /**
     * Bug batch 3 · E (2026-10-01) fixed the amount sent: before it, pay() sent round(amount, 2) (KWD 3.375 as 3.38). A
     * payment created before that fix may still come back at that 2-decimal amount; anything created after it must be
     * exact (Settlement::amountMatches).
     */
    private const LEGACY_2DP_BEFORE = '2026-10-02 00:00:00';

    public function key(): string
    {
        return 'flutterwave';
    }

    public function initiate(Transaction $transaction): Response
    {
        $secret = $this->secret($transaction);
        if ($secret === '') {
            app_alert_error(___('This payment method is not configured.'));

            return back();
        }
        $currency = $this->currency($transaction);

        try {
            $res = Http::withToken($secret)->acceptJson()->post(self::BASE.'/payments', [
                'tx_ref' => (string) $transaction->id,
                // Bug batch 3 · E: v3 takes MAJOR units at the currency's own decimals (KWD 3.375, UGX 5000), not 2.
                'amount' => GatewayMinorUnits::majorFloatFromMoney($transaction->money('amount'), $currency),
                'currency' => $currency,
                'redirect_url' => route('ipn', 'flutterwave'),
                'customer' => ['email' => GatewayBuyer::of($transaction)['email'] ?: 'guest@example.com'],
            ]);
            $link = $res->json('data.link');
        } catch (\Throwable $e) {
            Log::error('[flutterwave] payment create failed: '.$e->getMessage());
            $link = null;
        }

        if (! is_string($link) || $link === '') {
            app_alert_error(___('Could not start the payment. Please try again.'));

            return redirect(Settlement::cancelUrl($transaction));
        }

        return redirect()->away($link);
    }

    /** Redirect return from the hosted page: verify, settle once, then the buyer's page. */
    public function handleReturn(Request $request): Response
    {
        $flwId = $request->get('transaction_id');
        $transaction = $this->pendingTransaction($request->get('tx_ref'));
        if ($request->get('status') === 'cancelled' || ! is_scalar($flwId) || (string) $flwId === '') {
            app_alert_error(___('Payment was cancelled.'));

            return redirect()->to($transaction !== null ? Settlement::cancelUrl($transaction) : AccountPages::url());
        }
        if ($transaction === null) {
            return Settlement::invalid();
        }
        if (! $this->paymentPays((string) $flwId, $transaction)) {
            Log::info("[flutterwave] rejected tx {$flwId} for transaction {$transaction->id}");

            return Settlement::failed();
        }

        // J25-7: claim + durable credit (outbox), once per "flutterwave confirmed this payment paid" (a webhook that got
        // there first makes this a duplicate: the buyer still sees the paid page).
        return WebhookReceipts::once(
            'flutterwave',
            WebhookReceipts::key($transaction->id, 'paid'),
            fn (): Response => Settlement::settleForBuyer($transaction, 'flutterwave', (string) $flwId, 'subscription', claim: true)
                ?? Settlement::confirmingForBuyer($transaction->fresh(), true),
            fn (): Response => Settlement::confirmingForBuyer($transaction->fresh(), true),
        );
    }

    /** Server-to-server webhook (`verif-hash`). WI-15-sec: fails closed without a configured secret hash. */
    public function handleWebhook(Request $request): Response
    {
        $expected = trim((string) config('settings.flutterwave_secret_hash'));
        if ($expected === '' || ! hash_equals($expected, (string) $request->header('verif-hash'))) {
            return response('invalid signature', 401);
        }

        $flwId = data_get($request->all(), 'data.id');
        $transaction = $this->pendingTransaction(data_get($request->all(), 'data.tx_ref'));
        if ($transaction !== null && is_scalar($flwId) && (string) $flwId !== '' && $this->paymentPays((string) $flwId, $transaction)) {
            WebhookReceipts::once('flutterwave', WebhookReceipts::key($transaction->id, 'paid'), fn (): bool => Settlement::settle($transaction, 'flutterwave', (string) $flwId, claim: true), false);
        }

        return response('OK', 200);
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        return Settlement::noAutomaticRefund();
    }

    /* ------------------------------------------------------------ internals */

    /**
     * WI-15-sec: the Flutterwave transaction, read with THIS transaction's key, is bound to it: its own tx_ref is our id,
     * it is successful, for exactly our amount, in the currency we charged.
     */
    private function paymentPays(string $flwId, Transaction $transaction): bool
    {
        $secret = $this->secret($transaction);
        if ($secret === '') {
            return false;
        }
        try {
            $data = Http::withToken($secret)->acceptJson()->get(self::BASE.'/transactions/'.rawurlencode($flwId).'/verify')->json('data');
        } catch (\Throwable $e) {
            Log::error('[flutterwave] verify failed: '.$e->getMessage());

            return false;
        }
        if (! is_array($data)) {
            return false;
        }
        $currency = $this->currency($transaction);
        $paid = data_get($data, 'amount');
        $paid = is_int($paid) || is_float($paid) || is_string($paid) ? $paid : '';
        $paidCurrency = strtoupper((string) data_get($data, 'currency'));

        return (string) data_get($data, 'tx_ref') === (string) $transaction->id
            && strtolower((string) data_get($data, 'status')) === 'successful'
            && $currency !== ''
            && $paidCurrency === $currency
            && ($this->legacyAmount($transaction, $paid, $currency)
                || Settlement::amountMatches($transaction, $paid, $paidCurrency, 'flutterwave', $currency));
    }

    /** A payment created before the decimals fix, paid at the 2-decimal amount the old pay() sent. */
    private function legacyAmount(Transaction $transaction, mixed $paid, string $currency): bool
    {
        if ($transaction->created_at === null || ! Carbon::parse($transaction->created_at)->lt(Carbon::parse(self::LEGACY_2DP_BEFORE)) || ! is_numeric($paid)) {
            return false;
        }
        $decimals = GatewayMinorUnits::decimals($currency);
        $minor = static fn (float $v): int => (int) round($v * (10 ** $decimals));

        return $minor((float) $paid) === $minor(round((float) $transaction->amount, 2));
    }

    private function currency(Transaction $transaction): string
    {
        // Gateway-currency bug fix: the transaction's own currency, never the store's/platform's current one.
        $fallback = Settlement::usesStoreGateway($transaction)
            ? (string) (store_options($transaction->store_id)->currency_code ?? 'USD')
            : platform_currency();

        return strtoupper(Settlement::currency($transaction, $fallback));
    }

    /** The secret key of the account this transaction is paid into: the store's (orders, store payments) or the platform's. */
    private function secret(Transaction $transaction): string
    {
        $secret = Settlement::usesStoreGateway($transaction)
            ? (store_options($transaction->store_id)->restaurant_flutterwave_secret_key ?? null)
            : config('settings.flutterwave_secret_key');

        // Lane D: a test key (FLWSECK_TEST…) only while payment sandboxes are allowed.
        return GatewaySandbox::key('flutterwave', is_scalar($secret) ? trim((string) $secret) : '');
    }

    private function pendingTransaction(mixed $reference): ?Transaction
    {
        if (! is_scalar($reference) || ! ctype_digit((string) $reference)) {
            return null;
        }

        return Transaction::where('id', $reference)->whereNull('status')->whereNull('payment_id')->first();
    }
}
