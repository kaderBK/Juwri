<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Brick\Math\BigDecimal;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\StoreCustomerGateways;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\AccountPages;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * G9 GoCardless (bank debit / Direct Debit) — PLATFORM PURCHASES ONLY. P1-23 part 6: moved from the hand-written
 * GocardlessController into the adapter family, same wire protocol — the routes, `redirect_uri` = `webhook/gocardless`,
 * `exit_uri` = `ipn/gocardless` and the payment metadata `transaction_id` are unchanged.
 *
 * Why platform-only ({@see StoreCustomerGateways::PLATFORM_ONLY}): it is a mandate flow — the payer authorises a Direct
 * Debit and the money is collected ~3 working days later (Bacs/SEPA), confirmed only by a later webhook. That fits plan
 * and add-on billing of the platform's own customers; a store's diner buying a gift card, topping up store credit or
 * paying a deposit expects to be done at checkout, stores have no GoCardless settings (no store BYOK), and there are no
 * cards and no SAR. So orders AND store payments are refused before any call (404, as orders were), it is never offered
 * to store customers, and the only keys are the platform's (`settings.gocardless_*`).
 *
 * initiate: billing_request (payment_request in MINOR units + mandate_request, metadata.transaction_id) →
 * billing_request_flow → the authorisation URL. handleWebhook: `Webhook-Signature` = HMAC-SHA256 hex of the RAW body
 * with the endpoint secret (401 without, no side effects); for a payments confirmed/paid event the payment is fetched
 * server-side, its metadata names OUR transaction, the exact amount and currency must match; settles once
 * (WebhookReceipts + claim). handleReturn: mandate pending — display-only. No refund API wired.
 */
class GocardlessAdapter implements GatewayAdapter
{
    private const BASE_SANDBOX = 'https://api-sandbox.gocardless.com';

    private const BASE_LIVE = 'https://api.gocardless.com';

    private const API_VERSION = '2015-07-06';

    public function key(): string
    {
        return 'gocardless';
    }

    public function initiate(Transaction $transaction): Response
    {
        // Bank debit is platform billing only — never an order or a store's own customer's payment.
        abort_if(Settlement::usesStoreGateway($transaction) || $transaction->transaction_method === 'order', 404);

        $cancelUrl = Settlement::cancelUrl($transaction);
        $creds = $this->creds();
        if ($creds['token'] === '') {
            app_alert_error(___('This payment method is not configured.'));

            return redirect($cancelUrl);
        }

        $currency = $this->currency($transaction);
        // MINOR units (pence/cents), exact — GoCardless amounts are integers. before: (int) round(amount * 100).
        $amountMinor = GatewayMinorUnits::gatewayMinor('gocardless', $transaction->money('amount'), $currency);
        if ($amountMinor === null) {
            app_alert_error(___('Could not start the payment. Please try again.'));

            return redirect($cancelUrl);
        }

        try {
            $brResp = $this->http($creds)->post($creds['base'].'/billing_requests', [
                'billing_requests' => [
                    // payment_request COLLECTS against the mandate; its metadata round-trips onto the created payment so
                    // the confirmed webhook can resolve OUR transaction.
                    'payment_request' => [
                        'amount' => $amountMinor,
                        'currency' => $currency,
                        'description' => 'Subscription',
                        'metadata' => ['transaction_id' => (string) $transaction->id],
                    ],
                    'mandate_request' => ['currency' => $currency],
                    'metadata' => ['transaction_id' => (string) $transaction->id],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[gocardless] billing_requests failed: '.$e->getMessage());
            app_alert_error(___('The payment service is unavailable right now. Please try again.'));

            return redirect($cancelUrl);
        }

        $billingRequestId = $brResp->json('billing_requests.id');
        if (! $brResp->successful() || ! is_string($billingRequestId) || $billingRequestId === '') {
            Log::warning('[gocardless] billing_requests rejected: '.$brResp->status());
            app_alert_error(___('Could not start the payment. Please try again.'));

            return redirect($cancelUrl);
        }

        // Tag the pending row with the driver for reconciliation.
        $transaction->forceFill(['transaction_gatway' => 'gocardless'])->save();

        try {
            $flowResp = $this->http($creds)->post($creds['base'].'/billing_request_flows', [
                'billing_request_flows' => [
                    'redirect_uri' => route('webhook', 'gocardless'),
                    'exit_uri' => route('ipn', 'gocardless'),
                    'links' => ['billing_request' => $billingRequestId],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[gocardless] billing_request_flows failed: '.$e->getMessage());
            app_alert_error(___('The payment service is unavailable right now. Please try again.'));

            return redirect($cancelUrl);
        }

        $authUrl = $flowResp->json('billing_request_flows.authorisation_url');
        if ($flowResp->successful() && is_string($authUrl) && $authUrl !== '') {
            return redirect()->away($authUrl);
        }

        Log::warning('[gocardless] billing_request_flows rejected: '.$flowResp->status());
        app_alert_error(___('Could not start the payment. Please try again.'));

        return redirect($cancelUrl);
    }

    /** Customer return from the hosted flow: the mandate is pending — display-only, never settles. */
    public function handleReturn(Request $request): Response
    {
        app_alert_info(___('Your bank mandate is pending. Your plan will activate once the payment is confirmed.'));

        return redirect(AccountPages::url());
    }

    /** Signed webhook: HMAC-SHA256 over the RAW body, then settle on payments confirmed/paid. */
    public function handleWebhook(Request $request): Response
    {
        $creds = $this->creds();
        $raw = $request->getContent();
        $given = (string) $request->header('Webhook-Signature', '');
        if ($creds['secret'] === '' || $given === '' || ! hash_equals(hash_hmac('sha256', $raw, $creds['secret']), $given)) {
            return response('invalid signature', 401);
        }

        $payload = json_decode($raw, true);
        $events = is_array($payload) && is_array($payload['events'] ?? null) ? $payload['events'] : [];
        foreach ($events as $event) {
            // 'failed' / 'cancelled' and other resources leave the pending transaction untouched.
            if (is_array($event) && ($event['resource_type'] ?? null) === 'payments' && in_array($event['action'] ?? null, ['confirmed', 'paid'], true)) {
                $this->settleFromEvent($event, $creds);
            }
        }

        return response('ok', 200);
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
     * The event carries only links.payment: GET the payment server-side (its metadata names OUR transaction, its amount
     * and currency must be exactly ours), then settle once. A payment that cannot be fetched never settles.
     *
     * @param  array<mixed>  $event
     * @param  array{token: string, secret: string, base: string}  $creds
     */
    private function settleFromEvent(array $event, array $creds): void
    {
        $paymentId = data_get($event, 'links.payment');
        if (! is_string($paymentId) || $paymentId === '') {
            return; // nothing to verify against → never settles (before: settled from the event's metadata alone)
        }
        try {
            $resp = $this->http($creds)->get($creds['base'].'/payments/'.rawurlencode($paymentId));
        } catch (\Throwable $e) {
            Log::warning('[gocardless] payment fetch failed: '.$e->getMessage());

            return;
        }
        if (! $resp->successful()) {
            return;
        }

        $txId = $resp->json('payments.metadata.transaction_id');
        $transaction = is_scalar($txId) && ctype_digit((string) $txId)
            ? Transaction::where([['id', (string) $txId], ['status', null]])->first()
            : null;
        if ($transaction === null || Settlement::usesStoreGateway($transaction) || $transaction->transaction_method === 'order') {
            return; // unknown, already settled, or not a platform purchase (never paid through here)
        }

        $currency = $this->currency($transaction);
        $paidMinor = $resp->json('payments.amount');
        $paidCurrency = $resp->json('payments.currency');
        $paid = is_int($paidMinor) || (is_string($paidMinor) && ctype_digit($paidMinor))
            ? (string) BigDecimal::ofUnscaledValue((int) $paidMinor, GatewayMinorUnits::decimals($currency))
            : '';
        if (! Settlement::amountMatches($transaction, $paid, is_scalar($paidCurrency) ? (string) $paidCurrency : null, 'gocardless', $currency)) {
            return;
        }

        // J25-7: "gocardless confirmed this payment paid" — a repeated or concurrent confirmation is a no-op.
        WebhookReceipts::once('gocardless', WebhookReceipts::key($transaction->id, 'paid'), fn (): bool => Settlement::settle($transaction, 'gocardless', null, claim: true), false);
    }

    private function currency(Transaction $transaction): string
    {
        return strtoupper((string) ($transaction->currency_code ?: 'GBP'));
    }

    /** @param  array{token: string, secret: string, base: string}  $creds */
    private function http(array $creds): PendingRequest
    {
        return Http::withToken($creds['token'])->withHeaders(['GoCardless-Version' => self::API_VERSION])->acceptJson();
    }

    /** @return array{token: string, secret: string, base: string} the platform's account (the only one). */
    private function creds(): array
    {
        $text = static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';

        $sandbox = gateway_is_sandbox(config('settings.gocardless_sandbox', true));

        return GatewaySandbox::gate('gocardless', $sandbox, [
            'token' => $text(config('settings.gocardless_access_token')),
            'secret' => $text(config('settings.gocardless_webhook_secret')),
            'base' => $sandbox ? self::BASE_SANDBOX : self::BASE_LIVE,
        ]);
    }
}
