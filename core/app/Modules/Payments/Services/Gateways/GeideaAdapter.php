<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayCurrencies;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * G3 Geidea (docs.geidea.net) — KSA SAMA-licensed acquirer (HPP flow). P1-23 part 5: moved from the hand-written
 * GeideaController into the adapter family; same routes, callback/return URLs and signatures, so a session opened by
 * the old controller still settles here.
 *
 * Flow: a signed payment-intent session (HTTP Basic merchant_public_key : api_password) → the buyer goes to the
 * GeideaCheckout redirect URL → Geidea POSTs our callbackUrl (`webhook/geidea`) server-to-server → we VERIFY the
 * base64 HMAC-SHA256 callback signature (keyed with the API password; bad → 401, no side effect), then status, the
 * exact amount and the currency before settling once (status IS NULL + WebhookReceipts).
 *
 * Keys: the STORE's for orders and store payments (gift cards, top-ups, deposits; {@see Settlement::usesStoreGateway()}),
 * the platform's for plans/add-ons. The return (`ipn/geidea`) is display-only. No refund API wired.
 */
class GeideaAdapter implements GatewayAdapter
{
    private const BASE = 'https://api.ksamerchant.geidea.net';

    public function key(): string
    {
        return 'geidea';
    }

    public function initiate(Transaction $transaction): Response
    {
        if (($invalid = GatewayBuyer::requireOrderContact($transaction)) !== null) {
            return $invalid;
        }
        $cancelUrl = Settlement::cancelUrl($transaction);

        $creds = $this->creds($transaction);
        if ($creds['public_key'] === '' || $creds['password'] === '') {
            app_alert_error(___('This payment method is not configured.'));

            return redirect($cancelUrl);
        }

        // The amount at Geidea's own 2 decimals (GatewayMinorUnits::GATEWAY_MAX_DECIMALS), or a refusal — never rounded.
        $currency = $this->currency($transaction);
        [$amount, $refusal] = GatewayCurrencies::amountOrRefusal('geidea', $transaction, $currency);
        if ($amount === null) {
            app_alert_error((string) $refusal);

            return redirect($cancelUrl);
        }
        $ref = (string) $transaction->id;
        $timestamp = now()->toIso8601String();
        $signature = base64_encode(hash_hmac('sha256', $creds['public_key'].$amount.$currency.$ref.$timestamp, $creds['password'], true));

        try {
            $resp = Http::withBasicAuth($creds['public_key'], $creds['password'])->acceptJson()
                ->post(self::BASE.'/payment-intent/api/v2/direct/session', [
                    'amount' => $amount,
                    'currency' => $currency,
                    'merchantReferenceId' => $ref,
                    'callbackUrl' => route('webhook', 'geidea'),
                    'returnUrl' => route('ipn', 'geidea'),
                    'timestamp' => $timestamp,
                    'signature' => $signature,
                ]);
        } catch (\Throwable $e) {
            Log::warning('[geidea] session create failed: '.$e->getMessage());
            app_alert_error(___('The payment service is unavailable right now. Please try again.'));

            return redirect($cancelUrl);
        }

        $redirectUrl = $resp->json('session.redirectUrl');
        if ($resp->successful() && is_string($redirectUrl) && $redirectUrl !== '') {
            return redirect()->away($redirectUrl);
        }

        Log::warning('[geidea] session rejected: '.$resp->status());
        app_alert_error(___('Could not start the payment. Please try again.'));

        return redirect($cancelUrl);
    }

    /** Customer return (returnUrl): display-only, never settles (the callback does). */
    public function handleReturn(Request $request): Response
    {
        $ref = $request->input('merchantReferenceId') ?: $request->input('orderId');

        return Settlement::confirmingForBuyer(is_scalar($ref) && (string) $ref !== '' ? Transaction::find($ref) : null);
    }

    /** Server-to-server callback (callbackUrl): HMAC first, then status + amount + currency, then settle once. */
    public function handleWebhook(Request $request): Response
    {
        $ref = $request->input('merchantReferenceId');
        if (! is_scalar($ref) || (string) $ref === '') {
            return response('missing ref', 200);
        }
        $ref = (string) $ref;

        // Tenant/transaction resolved from OUR merchant reference id; unknown or already settled → 200 no-op.
        $transaction = Transaction::where([['id', $ref], ['status', null], ['payment_id', null]])->first();
        if ($transaction === null) {
            return response('ok', 200);
        }

        $creds = $this->creds($transaction);
        if ($creds['public_key'] === '' || $creds['password'] === '') {
            return response('unconfigured', 200);
        }

        $orderId = (string) $request->input('orderId', '');
        $amount = (string) $request->input('orderAmount', '');
        $currency = (string) $request->input('orderCurrency', '');
        $status = (string) $request->input('status', '');
        $timestamp = (string) $request->input('timestamp', '');
        $provided = (string) $request->input('signature', '');

        $expected = base64_encode(hash_hmac('sha256', $creds['public_key'].$amount.$currency.$orderId.$status.$ref.$timestamp, $creds['password'], true));
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            Log::warning("[geidea] bad callback signature for tx {$transaction->id}");

            return response('invalid signature', 401);
        }

        // J25-7: once per verified event (WebhookReceipts): a re-delivered or concurrent copy is a 200 no-op.
        return WebhookReceipts::once('geidea', WebhookReceipts::key($orderId !== '' ? $orderId : $ref, $status, WebhookReceipts::fingerprint($request)), function () use ($transaction, $status, $amount, $currency) {
            if (! in_array($status, ['Success', 'Paid'], true)) {
                return response('not paid', 200);
            }

            // Exact at Geidea's 2 decimals, in our currency.
            $ourCurr = $this->currency($transaction);
            if (! GatewayMinorUnits::paidMatches('geidea', $amount, $transaction->money('amount'), $ourCurr)) {
                Log::warning("[geidea] amount mismatch tx {$transaction->id}: claimed {$amount}");

                return response('amount mismatch', 200);
            }
            if ($currency !== '' && strtoupper($currency) !== $ourCurr) {
                Log::warning("[geidea] currency mismatch tx {$transaction->id}: {$currency} vs {$ourCurr}");

                return response('currency mismatch', 200);
            }

            // J25-7: mark paid + credit message in one DB transaction; the credit runs through the outbox (retried).
            Settlement::settle($transaction, 'geidea', null, claim: true);

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

    /** The transaction's own stamped currency (SAR for an unstamped legacy row, as before). */
    private function currency(Transaction $transaction): string
    {
        return strtoupper((string) ($transaction->currency_code ?: 'SAR'));
    }

    /** @return array{public_key: string, password: string, sandbox: bool} */
    private function creds(Transaction $transaction): array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $opts = store_options($transaction->store_id);
            [$pk, $pass, $sandbox] = [$opts->restaurant_geidea_merchant_public_key ?? null, $opts->restaurant_geidea_api_password ?? null, gateway_is_sandbox($opts->restaurant_geidea_sandbox_mode ?? null)];
        } else {
            [$pk, $pass, $sandbox] = [config('settings.geidea_merchant_public_key'), config('settings.geidea_api_password'), gateway_is_sandbox(config('settings.geidea_sandbox', true))];
        }

        return GatewaySandbox::gate('geidea', $sandbox, ['public_key' => is_scalar($pk) ? (string) $pk : '', 'password' => is_scalar($pass) ? (string) $pass : '', 'sandbox' => $sandbox]);
    }
}
