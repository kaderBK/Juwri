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
 * B2 QRPay (syrpayment.com) — Syrian acquirer (SPEC §3: licensing + settlement rail to verify before money flows).
 * P1-23 part 6: moved from the hand-written QrpayController into the adapter family, same wire protocol — the routes,
 * `return_url` = `ipn/qrpay`, the callback `webhook/qrpay` and our id in `custom` are unchanged, so a payment opened by
 * the old controller still settles here.
 *
 * API (SPEC §3): POST /authentication/token {client_id, secret_id} → a 10-minute access_token (fetched fresh per request,
 * never stored); POST /payment/create (Bearer) {amount 2-dp, currency, return_url, cancel_url, custom} → payment_url;
 * the status call (ASSUMED POST /payment/status {custom}) → {data:{custom, amount?, currency?}, message:{success}}.
 * SKELETON STATE (unchanged): the base URLs, the status path and its amount/currency fields are `TODO(docs)`.
 *
 * Keys: the STORE's (client id + vaulted secret id) for orders and store payments (gift cards, top-ups, deposits;
 * {@see Settlement::usesStoreGateway()}), the platform's for plans/add-ons; no key = refused. Return and callback only
 * name OUR pending transaction; that account's status must say success, name the same `custom`, for the exact amount
 * and currency; settles once (WebhookReceipts + claim). No refund API wired.
 */
class QrpayAdapter implements GatewayAdapter
{
    /** TODO(docs): confirm the production base URL (syrpayment.com/developer). */
    private const BASE = 'https://api.syrpayment.com';

    public function key(): string
    {
        return 'qrpay';
    }

    public function initiate(Transaction $transaction): Response
    {
        $creds = $this->creds($transaction);
        $token = $this->token($creds);
        $paymentUrl = $token !== null ? $this->create($transaction, $creds, $token) : null;
        if ($paymentUrl === null) {
            app_alert_error(___('This payment method is not yet available. Please choose another.'));

            return redirect(Settlement::retryUrl($transaction));
        }

        return redirect()->away($paymentUrl);
    }

    /** Customer return (return_url): verify, then display-only (store payments back to their store). */
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

    /** The status of OUR pending transaction from the account it is paid into, then settle once. */
    public function verifyAndSettle(?string $orderId): bool
    {
        $transaction = $orderId !== null
            ? Transaction::where([['id', $orderId], ['status', null], ['payment_id', null]])->first()
            : null;
        if ($transaction === null) {
            return false; // unknown or already settled
        }
        $creds = $this->creds($transaction);
        $token = $this->token($creds);
        $status = $token !== null ? $this->status($transaction, $creds, $token) : null;
        if ($status === null || ! $status['success']) {
            return false; // not configured / unreadable / declined / pending
        }
        // `custom` must name OUR transaction (a reply about another payment never settles this one).
        if ($status['custom'] !== null && $status['custom'] !== (string) $transaction->id) {
            PaymentLog::webhookRejected('qrpay', 'custom mismatch', null, ['transaction_id' => $transaction->id]);

            return false;
        }
        $paid = $status['amount'];
        if (! Settlement::amountMatches($transaction, is_int($paid) || is_float($paid) || is_string($paid) ? $paid : '', $status['currency'], 'qrpay', $this->currency($transaction))) {
            PaymentLog::webhookRejected('qrpay', 'amount or currency mismatch', null, ['transaction_id' => $transaction->id]);

            return false;
        }

        // J25-7: no event id — "qrpay confirmed this payment paid"; a repeated or concurrent confirmation is a no-op.
        return WebhookReceipts::once('qrpay', WebhookReceipts::key($transaction->id, 'paid'), fn (): bool => Settlement::settle($transaction, 'qrpay', null, claim: true), false);
    }

    /* ------------------------------------------------------------ provider calls */

    /**
     * A fresh access token (10-minute TTL, never stored), or null when not configured / refused.
     *
     * @param  array{client_id: string, secret_id: string, sandbox: bool}  $creds
     */
    private function token(array $creds): ?string
    {
        if ($creds['client_id'] === '' || $creds['secret_id'] === '') {
            return null;
        }
        try {
            $resp = Http::acceptJson()->post($this->base($creds['sandbox']).'/authentication/token', [
                'client_id' => $creds['client_id'],
                'secret_id' => $creds['secret_id'],
            ]);
        } catch (\Throwable $e) {
            PaymentLog::failure('qrpay', $e);

            return null;
        }
        $token = $resp->successful() ? $resp->json('access_token') : null;

        return is_scalar($token) && (string) $token !== '' ? (string) $token : null;
    }

    /** @param  array{client_id: string, secret_id: string, sandbox: bool}  $creds */
    private function create(Transaction $transaction, array $creds, string $token): ?string
    {
        // SPEC §3: amount = 2-dp decimal string, currency = alpha-3 upper.
        $currency = $this->currency($transaction);
        try {
            $resp = Http::withToken($token)->acceptJson()->post($this->base($creds['sandbox']).'/payment/create', [
                'amount' => GatewayMinorUnits::majorStringFromMoney($transaction->money('amount'), $currency),
                'currency' => $currency,
                'return_url' => route('ipn', 'qrpay'),
                'cancel_url' => Settlement::retryUrl($transaction),
                'custom' => (string) $transaction->id,
            ]);
        } catch (\Throwable $e) {
            PaymentLog::failure('qrpay', $e, ['transaction_id' => $transaction->id]);

            return null;
        }
        if (! $resp->successful()) {
            return null;
        }
        $url = $resp->json('payment_url') ?: $resp->json('data.payment_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @param  array{client_id: string, secret_id: string, sandbox: bool}  $creds
     * @return array{success: bool, custom: ?string, amount: mixed, currency: ?string}|null
     */
    private function status(Transaction $transaction, array $creds, string $token): ?array
    {
        try {
            // TODO(docs): confirm the status endpoint path + query key.
            $resp = Http::withToken($token)->acceptJson()->post($this->base($creds['sandbox']).'/payment/status', ['custom' => (string) $transaction->id]);
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('qrpay', $e, ['transaction_id' => $transaction->id]);

            return null;
        }
        if (! $resp->successful()) {
            return null;
        }
        $success = $resp->json('message.success');
        $custom = $resp->json('data.custom');
        if ($success === null && $custom === null) {
            return null; // unreadable / empty body
        }
        $currency = $resp->json('data.currency');

        return [
            'success' => $success === true || $success === 1 || $success === '1' || $success === 'true',
            'custom' => is_scalar($custom) ? (string) $custom : null,
            // TODO(docs): the documented status shape omits amount/currency — assumed data.amount / data.currency.
            'amount' => $resp->json('data.amount'),
            'currency' => is_scalar($currency) && (string) $currency !== '' ? (string) $currency : null,
        ];
    }

    /* ------------------------------------------------------------ internals */

    private function orderId(Request $request): ?string
    {
        $id = $request->input('custom') ?: $request->input('order_id');

        return is_scalar($id) && ctype_digit((string) $id) ? (string) $id : null;
    }

    private function currency(Transaction $transaction): string
    {
        return strtoupper((string) ($transaction->currency_code ?: 'SYP'));
    }

    /**
     * Lane D: QRPay has no known sandbox host (the old SANDBOX_BASE was the live host), so a sandbox-mode credential set
     * is refused in {@see creds()} and never reaches here: only the live host is ever called.
     */
    private function base(bool $sandbox): string
    {
        return self::BASE;
    }

    /**
     * The account a transaction is paid into: the STORE's for orders and store payments, the platform's otherwise.
     *
     * @return array{client_id: string, secret_id: string, sandbox: bool}
     */
    private function creds(Transaction $transaction): array
    {
        $text = static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';
        if (Settlement::usesStoreGateway($transaction)) {
            $opts = store_options($transaction->store_id);

            return self::refuseSandbox([
                'client_id' => $text($opts->restaurant_qrpay_client_id ?? null),
                'secret_id' => $text(GatewaySecretVault::get($transaction->store_id, 'restaurant_qrpay_secret_id')),
                'sandbox' => gateway_is_sandbox($opts->restaurant_qrpay_sandbox_mode ?? null, false),
            ]);
        }

        return self::refuseSandbox([
            'client_id' => $text(config('settings.qrpay_client_id')),
            'secret_id' => $text(config('settings.qrpay_secret_id')),
            'sandbox' => gateway_is_sandbox(config('settings.qrpay_sandbox'), false),
        ]);
    }

    /**
     * Lane D: no QRPay sandbox host exists yet, so an explicit sandbox mode is "not configured" (even with payment
     * sandboxes on) instead of silently charging on the live host. A blank mode is live (there is no switch in the UI).
     *
     * @param  array{client_id: string, secret_id: string, sandbox: bool}  $creds
     * @return array{client_id: string, secret_id: string, sandbox: bool}
     */
    private static function refuseSandbox(array $creds): array
    {
        if (! $creds['sandbox']) {
            return $creds;
        }
        Log::warning('[qrpay] sandbox mode refused: QRPay has no sandbox host configured');

        return ['client_id' => '', 'secret_id' => '', 'sandbox' => true];
    }
}
