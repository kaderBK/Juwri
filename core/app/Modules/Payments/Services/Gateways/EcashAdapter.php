<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * B1 eCash (ecash-pay.com) — Syrian licensed acquirer, hosted checkout + server-side status (SYP, 2 decimals). P1-23
 * part 6: moved from the hand-written EcashController into the adapter family, same wire protocol — the routes, the
 * success/failure URL `ipn/ecash`, the callback `webhook/ecash` and our id as `order_id` / `custom` are unchanged, so a
 * checkout opened by the old controller still settles here.
 *
 * SKELETON STATE (unchanged): endpoint paths, field names and the amount unit are ASSUMPTIONS (`TODO(docs)`) to confirm
 * against eCash merchant docs + sandbox before a live store enables it. Without credentials nothing is ever called.
 *
 * Keys: the STORE's (merchant/terminal id + the vaulted api key) for orders and store payments (gift cards, top-ups,
 * deposits; {@see Settlement::usesStoreGateway()}), the platform's for plans/add-ons; no key = refused.
 * Return and callback are never trusted: they only name OUR pending transaction, whose account's status fetch must say
 * SUCCESS/PAID/COMPLETED for the exact amount and currency; settles once (WebhookReceipts + claim). No refund API wired.
 */
class EcashAdapter implements GatewayAdapter
{
    private const BASE = 'https://api.ecash-pay.com';

    public function key(): string
    {
        return 'ecash';
    }

    public function initiate(Transaction $transaction): Response
    {
        $checkoutUrl = $this->createCheckout($transaction, $this->creds($transaction));
        if ($checkoutUrl === null) {
            // Not configured / not live / the create failed: settle nothing, back to pick another method.
            app_alert_error(___('This payment method is not yet available. Please choose another.'));

            return redirect(Settlement::retryUrl($transaction));
        }

        return redirect()->away($checkoutUrl);
    }

    /** Customer return: verify, then display-only (store payments back to their store). */
    public function handleReturn(Request $request): Response
    {
        $orderId = $this->orderId($request);
        $this->verifyAndSettle($orderId);

        return Settlement::confirmingForBuyer($orderId !== null ? Transaction::find($orderId) : null);
    }

    /** Server-to-server callback — verify, then acknowledge. Never settles from the body. */
    public function handleWebhook(Request $request): Response
    {
        $this->verifyAndSettle($this->orderId($request));

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

    /** Server-side status with the key of the account the transaction is paid into, then settle once. */
    public function verifyAndSettle(?string $orderId): bool
    {
        $transaction = $orderId !== null
            ? Transaction::where([['id', $orderId], ['status', null], ['payment_id', null]])->first()
            : null;
        if ($transaction === null) {
            return false; // unknown or already settled
        }

        $status = $this->fetchStatus($transaction, $this->creds($transaction));
        // TODO(docs): confirm the success token(s).
        if ($status === null || ! in_array($status['status'], ['SUCCESS', 'PAID', 'COMPLETED'], true)) {
            return false;
        }

        $currency = $this->currency($transaction);
        $paid = $status['amount'];
        if (! Settlement::amountMatches($transaction, is_int($paid) || is_float($paid) || is_string($paid) ? $paid : '', $status['currency'], 'ecash', $currency)) {
            PaymentLog::webhookRejected('ecash', 'amount or currency mismatch', null, ['transaction_id' => $transaction->id]);

            return false;
        }

        // J25-7: no event id — "ecash confirmed this payment paid"; a repeated or concurrent confirmation is a no-op.
        return WebhookReceipts::once('ecash', WebhookReceipts::key($transaction->id, 'paid'), fn (): bool => Settlement::settle($transaction, 'ecash', null, claim: true), false);
    }

    /* ------------------------------------------------------------ provider calls (stubs until the docs are confirmed) */

    /**
     * Start a hosted checkout: the redirect URL, or null when not configured / the create failed.
     *
     * @param  array{merchant_id: string, terminal_id: string, api_key: string, sandbox: bool}  $creds
     */
    private function createCheckout(Transaction $transaction, array $creds): ?string
    {
        if ($creds['api_key'] === '' || $creds['merchant_id'] === '') {
            return null;
        }
        $currency = $this->currency($transaction);
        // TODO(docs): confirm the amount UNIT (major decimal string used; minor integer would be fromMoney()).
        $amount = GatewayMinorUnits::majorStringFromMoney($transaction->money('amount'), $currency);

        try {
            // TODO(sandbox): confirm the create-checkout path and request/response shape.
            $resp = Http::withHeaders(['Authorization' => 'Bearer '.$creds['api_key'], 'Accept' => 'application/json'])
                ->post($this->base($creds['sandbox']).'/checkout', [
                    'merchant_id' => $creds['merchant_id'],
                    'terminal_id' => $creds['terminal_id'],
                    'amount' => $amount,
                    'currency' => $currency,
                    'order_id' => (string) $transaction->id,
                    'custom' => (string) $transaction->id,
                    'success_url' => route('ipn', 'ecash'),
                    'failure_url' => route('ipn', 'ecash'),
                    'callback_url' => route('webhook', 'ecash'),
                ]);
        } catch (\Throwable $e) {
            PaymentLog::failure('ecash', $e, ['transaction_id' => $transaction->id]);

            return null;
        }
        if (! $resp->successful()) {
            return null;
        }
        // TODO(docs): confirm the checkout URL field name.
        $url = $resp->json('checkout_url') ?: $resp->json('result.checkout_url') ?: $resp->json('data.redirect_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The status fetch — the ONLY source of truth for settlement. Null when not configured / the call failed.
     *
     * @param  array{merchant_id: string, terminal_id: string, api_key: string, sandbox: bool}  $creds
     * @return array{status: string, amount: mixed, currency: ?string}|null
     */
    private function fetchStatus(Transaction $transaction, array $creds): ?array
    {
        if ($creds['api_key'] === '' || $creds['merchant_id'] === '') {
            return null;
        }
        try {
            // TODO(sandbox): confirm the status endpoint + response shape.
            $resp = Http::withHeaders(['Authorization' => 'Bearer '.$creds['api_key'], 'Accept' => 'application/json'])
                ->post($this->base($creds['sandbox']).'/status', [
                    'merchant_id' => $creds['merchant_id'],
                    'order_id' => (string) $transaction->id,
                    'custom' => (string) $transaction->id,
                ]);
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('ecash', $e, ['transaction_id' => $transaction->id]);

            return null;
        }
        if (! $resp->successful()) {
            return null;
        }
        // TODO(docs): confirm the status/amount/currency field names.
        $status = $resp->json('status') ?? $resp->json('result.status') ?? $resp->json('data.status');
        if (! is_scalar($status)) {
            return null;
        }
        $currency = $resp->json('currency') ?? $resp->json('result.currency') ?? $resp->json('data.currency');

        return [
            'status' => strtoupper((string) $status),
            'amount' => $resp->json('amount') ?? $resp->json('result.amount') ?? $resp->json('data.amount'),
            'currency' => is_scalar($currency) && (string) $currency !== '' ? (string) $currency : null,
        ];
    }

    /* ------------------------------------------------------------ internals */

    private function orderId(Request $request): ?string
    {
        $id = $request->input('custom') ?: $request->input('order_id') ?: $request->input('order_number');

        return is_scalar($id) && ctype_digit((string) $id) ? (string) $id : null;
    }

    private function currency(Transaction $transaction): string
    {
        return strtoupper((string) ($transaction->currency_code ?: 'SYP'));
    }

    /** Lane D: no eCash sandbox host is known (the old SANDBOX_BASE was the live host); sandbox creds are refused in shape(). */
    private function base(bool $sandbox): string
    {
        return self::BASE;
    }

    /**
     * The account a transaction is paid into: the STORE's for orders and store payments, the platform's otherwise.
     *
     * @return array{merchant_id: string, terminal_id: string, api_key: string, sandbox: bool}
     */
    private function creds(Transaction $transaction): array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $opts = store_options($transaction->store_id);

            return $this->shape(
                $opts->restaurant_ecash_merchant_id ?? null,
                $opts->restaurant_ecash_terminal_id ?? null,
                GatewaySecretVault::get($transaction->store_id, 'restaurant_ecash_api_key'),
                gateway_is_sandbox($opts->restaurant_ecash_sandbox_mode ?? null, false),
            );
        }

        return $this->shape(config('settings.ecash_merchant_id'), config('settings.ecash_terminal_id'), config('settings.ecash_api_key'), gateway_is_sandbox(config('settings.ecash_sandbox'), false));
    }

    /** @return array{merchant_id: string, terminal_id: string, api_key: string, sandbox: bool} */
    private function shape(mixed $merchant, mixed $terminal, mixed $apiKey, bool $sandbox): array
    {
        $text = static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';

        // Lane D: no eCash sandbox host exists yet, so an explicit sandbox mode is "not configured" (even with payment
        // sandboxes on) instead of charging on the live host. A blank mode is live (no switch in the UI).
        if ($sandbox) {
            Log::warning('[ecash] sandbox mode refused: eCash has no sandbox host configured');

            return ['merchant_id' => '', 'terminal_id' => '', 'api_key' => '', 'sandbox' => true];
        }

        return ['merchant_id' => $text($merchant), 'terminal_id' => $text($terminal), 'api_key' => $text($apiKey), 'sandbox' => false];
    }
}
