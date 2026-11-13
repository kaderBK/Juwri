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
 * G1 Paylink / STC Pay (paylink.sa) — KSA redirect gateway. P1-23 part 5: moved from the hand-written PaylinkController
 * into the adapter family; same routes, callBackUrl and stored `details.paylink_transaction_no`, so an invoice opened by
 * the old controller still settles here. The Paylink Partner onboarding (PaylinkPartner) writes the same
 * `restaurant_paylink_*` options this adapter reads.
 *
 * initiate: auth (apiId/secretKey → Bearer id_token) → addInvoice (orderNumber = our id) → the hosted page.
 * handleWebhook (callBackUrl): Paylink callbacks are UNSIGNED — GetInvoice is the source of truth. The pending
 * transaction is resolved first (callback orderNumber); ITS account's credentials fetch the invoice, which must name
 * that same transaction and the invoice number stored at initiate, be Paid, for the exact amount and currency; settles
 * once. Without an orderNumber only the platform account can be asked, so only a platform purchase may settle that way
 * — never an order or a store payment (paid into the store's account).
 *
 * Keys: the STORE's for orders and store payments ({@see Settlement::usesStoreGateway()}), the platform's otherwise.
 * The return is display-only. No refund API wired.
 */
class PaylinkAdapter implements GatewayAdapter
{
    private const BASE_SANDBOX = 'https://restpilot.paylink.sa';

    private const BASE_LIVE = 'https://restapi.paylink.sa';

    public function key(): string
    {
        return 'paylink';
    }

    public function initiate(Transaction $transaction): Response
    {
        if (($invalid = GatewayBuyer::requireOrderContact($transaction)) !== null) {
            return $invalid;
        }
        $cancelUrl = Settlement::cancelUrl($transaction);
        $buyer = GatewayBuyer::of($transaction);

        $creds = $this->creds($transaction);
        if ($creds['api_id'] === '' || $creds['secret'] === '') {
            app_alert_error(___('This payment method is not configured.'));

            return redirect($cancelUrl);
        }
        // The amount at Paylink's 2 decimals, or a refusal — never rounded.
        $currency = $this->currency($transaction);
        [$amount, $refusal] = GatewayCurrencies::amountOrRefusal('paylink', $transaction, $currency);
        if ($amount === null) {
            app_alert_error((string) $refusal);

            return redirect($cancelUrl);
        }

        $token = $this->token($creds);
        if ($token === null) {
            app_alert_error(___('The payment service is unavailable right now. Please try again.'));

            return redirect($cancelUrl);
        }

        try {
            $resp = Http::withToken($token)->acceptJson()->post($creds['base'].'/api/addInvoice', [
                'orderNumber' => (string) $transaction->id,
                'amount' => (float) $amount, // Paylink's `amount` is a JSON number; the exact 2-decimal string above
                'clientName' => $buyer['name'],
                'clientMobile' => $buyer['phone'],
                'currency' => $currency,
                'callBackUrl' => route('webhook', 'paylink'),
                'products' => [[
                    'title' => $transaction->product_name ?: 'Order',
                    'price' => (float) $amount,
                    'qty' => 1,
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[paylink] addInvoice failed: '.$e->getMessage());
            app_alert_error(___('The payment service is unavailable right now. Please try again.'));

            return redirect($cancelUrl);
        }

        $json = $resp->json();
        $url = is_array($json) ? ($json['url'] ?? null) : null;
        if ($resp->successful() && is_string($url) && $url !== '') {
            // WI-06d: remember Paylink's invoice number for this transaction.
            if (is_scalar($json['transactionNo'] ?? null) && (string) $json['transactionNo'] !== '') {
                $details = (array) ($transaction->details ?? []);
                $details['paylink_transaction_no'] = (string) $json['transactionNo'];
                $transaction->forceFill(['details' => $details])->save();
            }

            return redirect()->away($url);
        }

        Log::warning('[paylink] addInvoice rejected: '.$resp->status());
        app_alert_error(___('Could not start the payment. Please try again.'));

        return redirect($cancelUrl);
    }

    /** Customer return — display-only, never settles. */
    public function handleReturn(Request $request): Response
    {
        $ref = $request->input('orderNumber') ?: $request->input('transactionNo');

        return Settlement::confirmingForBuyer(is_scalar($ref) && (string) $ref !== '' ? Transaction::find($ref) : null);
    }

    /** Callback (callBackUrl, Paylink appends ?transactionNo=…). Unsigned — settles ONLY after GetInvoice. */
    public function handleWebhook(Request $request): Response
    {
        $transactionNo = $request->input('transactionNo');
        $hintOrder = $request->input('orderNumber');
        $transactionNo = is_scalar($transactionNo) ? (string) $transactionNo : '';
        $hintOrder = is_scalar($hintOrder) ? (string) $hintOrder : '';
        if ($transactionNo === '' && $hintOrder === '') {
            return response('missing ref', 200);
        }

        // WI-06d: resolve OUR pending transaction first and verify with THAT transaction's account; the invoice must
        // then name this same transaction, so a paid invoice in one account can never settle another's transaction.
        if ($hintOrder !== '') {
            $transaction = $this->pending($hintOrder);
            if ($transaction === null) {
                return response('ok', 200); // unknown or already settled
            }
            $creds = $this->creds($transaction);
        } else {
            $transaction = null;
            $creds = $this->platformCreds();
        }

        $storedNo = (string) ($transaction?->details->paylink_transaction_no ?? '');
        if ($transactionNo === '') {
            $transactionNo = $storedNo !== '' ? $storedNo : $hintOrder;
        }
        if ($transaction !== null && $storedNo !== '' && $storedNo !== $transactionNo) {
            Log::warning("[paylink] invoice {$transactionNo} does not belong to tx {$transaction->id}");

            return response('invoice mismatch', 200);
        }
        if ($creds['api_id'] === '' || $creds['secret'] === '') {
            return response('unverified', 200);
        }
        $token = $this->token($creds);
        if ($token === null) {
            return response('unverified', 200);
        }

        try {
            $resp = Http::withToken($token)->acceptJson()->get($creds['base'].'/api/getInvoice/'.rawurlencode($transactionNo));
        } catch (\Throwable $e) {
            Log::warning('[paylink] getInvoice failed: '.$e->getMessage());

            return response('unverified', 200);
        }
        if (! $resp->successful()) {
            return response('unverified', 200);
        }
        $inv = $resp->json();
        $inv = is_array($inv) ? $inv : [];
        $orderNumber = $inv['orderNumber'] ?? null;
        $paidAmount = $inv['amount'] ?? null;
        $paidCurr = strtoupper(is_scalar($inv['currency'] ?? null) ? (string) $inv['currency'] : '');
        if (($inv['orderStatus'] ?? null) !== 'Paid' || ! is_scalar($orderNumber) || (string) $orderNumber === '') {
            return response('not paid', 200);
        }

        if ($transaction !== null) {
            if ((string) $orderNumber !== (string) $transaction->id) {
                Log::warning("[paylink] invoice {$transactionNo} names order {$orderNumber}, not tx {$transaction->id}");

                return response('invoice mismatch', 200);
            }
        } else {
            $transaction = $this->pending($orderNumber);
            if ($transaction === null) {
                return response('ok', 200); // unknown or already settled
            }
            $storedNo = (string) ($transaction->details->paylink_transaction_no ?? '');
            if ($storedNo !== '' && $storedNo !== $transactionNo) {
                Log::warning("[paylink] invoice {$transactionNo} does not belong to tx {$transaction->id}");

                return response('invoice mismatch', 200);
            }
            if (Settlement::usesStoreGateway($transaction)) {
                // A store order or store payment is paid into the store's own account, never the platform's.
                Log::warning("[paylink] platform invoice {$transactionNo} names store tx {$transaction->id}");

                return response('invoice mismatch', 200);
            }
        }

        // Exact amount at Paylink's decimals, in our currency.
        $ourCurr = $this->currency($transaction);
        if (! GatewayMinorUnits::paidMatches('paylink', $paidAmount, $transaction->money('amount'), $ourCurr)) {
            Log::warning("[paylink] amount mismatch tx {$transaction->id}: paid ".(is_scalar($paidAmount) ? (string) $paidAmount : '-'));

            return response('amount mismatch', 200);
        }
        if ($paidCurr !== '' && $paidCurr !== $ourCurr) {
            Log::warning("[paylink] currency mismatch tx {$transaction->id}: {$paidCurr} vs {$ourCurr}");

            return response('currency mismatch', 200);
        }

        // J25-7: no event id — "paylink confirmed this payment paid"; a repeat or concurrent copy is a no-op.
        return WebhookReceipts::once('paylink', WebhookReceipts::key($transactionNo, 'paid'), function () use ($transaction) {
            Settlement::settle($transaction, 'paylink', null, claim: true);

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

    private function currency(Transaction $transaction): string
    {
        return strtoupper((string) ($transaction->currency_code ?: 'SAR'));
    }

    /**
     * A Bearer id_token (short-lived; not cached across the 401 boundary).
     *
     * @param  array{api_id: string, secret: string, base: string}  $creds
     */
    private function token(array $creds): ?string
    {
        try {
            $resp = Http::acceptJson()->post($creds['base'].'/api/auth', ['apiId' => $creds['api_id'], 'secretKey' => $creds['secret'], 'persistToken' => false]);
        } catch (\Throwable $e) {
            Log::warning('[paylink] auth failed: '.$e->getMessage());

            return null;
        }
        $token = $resp->successful() ? $resp->json('id_token') : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function pending(mixed $id): ?Transaction
    {
        if (! is_scalar($id) || ! ctype_digit((string) $id)) {
            return null;
        }

        return Transaction::where([['id', (int) $id], ['status', null], ['payment_id', null]])->first();
    }

    /** @return array{api_id: string, secret: string, base: string} */
    private function creds(Transaction $transaction): array
    {
        if (! Settlement::usesStoreGateway($transaction)) {
            return $this->platformCreds();
        }
        $opts = store_options($transaction->store_id);

        return $this->shape($opts->restaurant_paylink_api_id ?? null, $opts->restaurant_paylink_secret_key ?? null, gateway_is_sandbox($opts->restaurant_paylink_sandbox_mode ?? null));
    }

    /** @return array{api_id: string, secret: string, base: string} */
    private function platformCreds(): array
    {
        return $this->shape(config('settings.paylink_api_id'), config('settings.paylink_secret_key'), gateway_is_sandbox(config('settings.paylink_sandbox', true)));
    }

    /** @return array{api_id: string, secret: string, base: string} */
    private function shape(mixed $apiId, mixed $secret, bool $sandbox): array
    {
        return GatewaySandbox::gate('paylink', $sandbox, [
            'api_id' => is_scalar($apiId) ? (string) $apiId : '',
            'secret' => is_scalar($secret) ? (string) $secret : '',
            'base' => $sandbox ? self::BASE_SANDBOX : self::BASE_LIVE,
        ]);
    }
}
