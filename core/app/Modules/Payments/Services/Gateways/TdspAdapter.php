<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Tlync\TlyncClient;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Symfony\Component\HttpFoundation\Response;

/**
 * TDSP / T-Lync (tlync.ly) — Libya, LYD only. P1-23 part 6: moved from the hand-written TdspController into the
 * adapter family, same wire protocol — the routes, `backend_url` = `webhook/tdsp`, `frontend_url` = `ipn/tdsp` and our
 * id as `custom_ref` are unchanged, so a payment opened by the old controller still settles here. The HTTP calls stay in
 * TlyncClient (shared with reseller BYOK billing).
 *
 * initiate (Bearer token, store id) → the purchase URL. The backend callback is UNSIGNED (WI-17b): it only names OUR
 * custom_ref. The pending transaction's account — the STORE's for orders and store payments (gift cards, top-ups,
 * deposits; {@see Settlement::usesStoreGateway()}), the platform's for plans/add-ons; no key = refused — reads the
 * receipt, which must be COMPLETED, in LYD, for our ref and the exact amount; settles once (WebhookReceipts + claim).
 * The return is display-only. No refund API wired (the old controller had none): tracked manual refund.
 */
class TdspAdapter implements GatewayAdapter
{
    public function key(): string
    {
        return 'tdsp';
    }

    public function initiate(Transaction $transaction): Response
    {
        if (($invalid = GatewayBuyer::requireOrderContact($transaction)) !== null) {
            return $invalid;
        }
        $cancelUrl = Settlement::cancelUrl($transaction);
        $buyer = GatewayBuyer::of($transaction);

        $creds = $this->creds($transaction);
        if ($creds['store_id'] === '' || $creds['token'] === '') {
            app_alert_error(___('This payment method is not configured.'));

            return redirect($cancelUrl);
        }
        // WI-06e: T-Lync charges LYD only — anything else would be charged as the same number of dinars.
        $currency = $this->currencyOf($transaction);
        if ($currency !== TlyncClient::CURRENCY) {
            Log::warning("[tdsp] tx {$transaction->id} is in {$currency}; T-Lync charges LYD only");
            app_alert_error(___('This payment method only accepts payments in Libyan dinars (LYD).'));

            return redirect($cancelUrl);
        }

        try {
            $resp = app(TlyncClient::class)->initiate($creds['base'], $creds['token'], [
                'id' => $creds['store_id'],
                'amount' => TlyncClient::amount($transaction->amount),
                'phone' => $buyer['phone'],
                'email' => $buyer['email'],
                'backend_url' => route('webhook', 'tdsp'),
                'frontend_url' => route('ipn', 'tdsp'),
                'custom_ref' => (string) $transaction->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[tdsp] initiate failed: '.$e->getMessage());
            app_alert_error(___('The payment service is unavailable right now. Please try again.'));

            return redirect($cancelUrl);
        }

        if (($url = TlyncClient::purchaseUrl($resp)) !== null) {
            return redirect()->away($url);
        }

        Log::warning('[tdsp] initiate rejected: '.$resp->body());
        app_alert_error(___('Could not start the payment. Please try again.'));

        return redirect($cancelUrl);
    }

    /** Customer return (frontend_url) — display-only, never settles (the callback does). */
    public function handleReturn(Request $request): Response
    {
        $ref = $request->input('custom_ref');

        return Settlement::confirmingForBuyer(is_scalar($ref) && ctype_digit((string) $ref) ? Transaction::find($ref) : null);
    }

    /** Backend callback (backend_url) — UNSIGNED: names the custom_ref to look up; the receipt decides. */
    public function handleWebhook(Request $request): Response
    {
        $ref = $request->input('custom_ref');
        $transaction = is_scalar($ref) && ctype_digit(trim((string) $ref))
            ? Transaction::where([['id', trim((string) $ref)], ['status', null], ['payment_id', null]])->first()
            : null;
        if ($transaction === null) {
            return response('ok', 200); // unknown or already settled — 200 so the gateway stops retrying
        }
        if ($this->currencyOf($transaction) !== TlyncClient::CURRENCY) {
            Log::warning("[tdsp] webhook for tx {$transaction->id} in ".$this->currencyOf($transaction).'; T-Lync settles LYD only');

            return response('currency mismatch', 200);
        }
        if (! $this->receiptPays($transaction)) {
            Log::warning("[tdsp] webhook receipt not verified for tx {$transaction->id}");

            return response('unverified', 200);
        }

        // J25-7: no event id — "tdsp confirmed this payment paid"; a repeated or concurrent confirmation is a no-op.
        return WebhookReceipts::once('tdsp', WebhookReceipts::key($transaction->id, 'paid'), function () use ($transaction) {
            Settlement::settle($transaction, 'tdsp', null, claim: true);

            return response('ok', 200);
        }, fn () => response('ok', 200));
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
     * WI-17b: the receipt of the account the transaction is paid into must be COMPLETED, in LYD, for our ref, for
     * exactly our amount (Settlement::amountMatches at T-Lync's 3 decimals; TlyncClient's integer-dirham check too).
     * Incomplete / 404 / 401 / 5xx / malformed → not paid.
     */
    private function receiptPays(Transaction $transaction): bool
    {
        $creds = $this->creds($transaction);
        if ($creds['store_id'] === '' || $creds['token'] === '') {
            return false;
        }
        try {
            $resp = app(TlyncClient::class)->receipt($creds['base'], $creds['token'], $creds['store_id'], (string) $transaction->id);
        } catch (\Throwable $e) {
            Log::warning('[tdsp] receipt call failed: '.$e->getMessage());

            return false;
        }
        if ($resp->status() !== 200) {
            return false;
        }
        $json = $resp->json();
        $receipt = TlyncClient::completedReceipt($json);
        if ($receipt === null) {
            return false;
        }
        $paid = data_get($json, 'data.amount');
        $ok = TlyncClient::receiptSettles($json, TlyncClient::transactionMinor($transaction->amount), (string) $transaction->id)
            && Settlement::amountMatches($transaction, is_int($paid) || is_float($paid) || is_string($paid) ? $paid : '', $receipt['currency'], 'tdsp', TlyncClient::CURRENCY);
        if (! $ok) {
            Log::warning("[tdsp] completed receipt does not match tx {$transaction->id} (currency / amount / ref)");
        }

        return $ok;
    }

    private function currencyOf(Transaction $transaction): string
    {
        return strtoupper(trim((string) $transaction->currency_code));
    }

    /**
     * The account a transaction is paid into: the STORE's for orders and store payments, the platform's otherwise.
     *
     * @return array{store_id: string, token: string, base: string}
     */
    private function creds(Transaction $transaction): array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $opts = store_options($transaction->store_id);
            [$store, $token, $sandbox] = [$opts->restaurant_tdsp_store_id ?? null, $opts->restaurant_tdsp_token ?? null, gateway_is_sandbox($opts->restaurant_tdsp_sandbox_mode ?? null)];
        } else {
            [$store, $token, $sandbox] = [config('settings.tdsp_store_id'), config('settings.tdsp_token'), gateway_is_sandbox(config('settings.tdsp_sandbox', true))];
        }

        return GatewaySandbox::gate('tdsp', $sandbox, [
            'store_id' => is_scalar($store) ? trim((string) $store) : '',
            'token' => is_scalar($token) ? trim((string) $token) : '',
            'base' => TlyncClient::base($sandbox),
        ]);
    }
}
