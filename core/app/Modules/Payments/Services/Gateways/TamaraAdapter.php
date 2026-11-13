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
 * G4 Tamara BNPL (KSA split-in-3/4, SAMA-licensed). P1-23 part 5: moved from the hand-written TamaraController into
 * the adapter family; same routes, merchant URLs and stored `details.tamara_order_id`, so a checkout opened by the old
 * controller still settles here.
 *
 * initiate: a hosted checkout session (POST /checkout, Bearer API token) in one of Tamara's own currencies
 * (GatewayCurrencies::FIXED, refused before any call) → Tamara's hosted flow. handleWebhook: the notification carries
 * a JWT (`tamaraToken`, HS256) signed with the merchant Notification Token of the account the transaction is paid into
 * — invalid → 401, no side effect; an expired token or a body contradicting it is refused; the Tamara order must be
 * the one created at initiate; we AUTHORISE then CAPTURE server-side and only a full capture of EXACTLY our amount in
 * our currency settles, once.
 *
 * Keys: the STORE's for orders and store payments ({@see Settlement::usesStoreGateway()}), the platform's otherwise.
 * Checkout visibility for orders is gated upstream by BnplEligibility. The return is display-only. No refund API wired.
 */
class TamaraAdapter implements GatewayAdapter
{
    private const BASE_SANDBOX = 'https://api-sandbox.tamara.co';

    private const BASE_LIVE = 'https://api.tamara.co';

    /**
     * The checkout `country_code` for each currency Tamara takes (docs.tamara.co/reference/createcheckoutsession:
     * currency enum SAR AED BHD KWD OMR, country_code enum SA AE BH KW OM).
     */
    public const COUNTRY = ['SAR' => 'SA', 'AED' => 'AE', 'BHD' => 'BH', 'KWD' => 'KW', 'OMR' => 'OM'];

    public function key(): string
    {
        return 'tamara';
    }

    public function initiate(Transaction $transaction): Response
    {
        if (($invalid = GatewayBuyer::requireOrderContact($transaction)) !== null) {
            return $invalid;
        }
        $cancelUrl = Settlement::cancelUrl($transaction);
        $buyer = GatewayBuyer::of($transaction);
        if (! Settlement::usesStoreGateway($transaction) && $buyer['email'] === '') {
            $email = request()->input('email');
            $buyer['email'] = is_string($email) ? $email : ''; // platform buyer without an account email, as before
        }

        $creds = $this->creds($transaction);
        if ($creds['api_token'] === '') {
            app_alert_error(___('This payment method is not configured.'));

            return redirect($cancelUrl);
        }

        $currency = $this->currency($transaction);
        // Only Tamara's own currencies (GatewayCurrencies::FIXED) — refused before any call.
        $refusal = GatewayCurrencies::refusal('tamara', $transaction);
        if ($refusal !== null) {
            app_alert_error($refusal);

            return redirect($cancelUrl);
        }
        // Currency-aware precision: fils currencies (KWD/BHD/OMR) keep 3 decimals — see GatewayMinorUnits.
        $amount = GatewayMinorUnits::majorFloatFromMoney($transaction->money('amount'), $currency);

        try {
            $resp = Http::withToken($creds['api_token'])->acceptJson()->post($creds['base'].'/checkout', [
                'order_reference_id' => (string) $transaction->id,
                'total_amount' => ['amount' => $amount, 'currency' => $currency],
                'country_code' => self::COUNTRY[$currency] ?? 'SA',
                'payment_type' => 'PAY_BY_INSTALMENTS',
                'items' => [[
                    'reference_id' => (string) $transaction->id,
                    'type' => 'Physical',
                    'name' => $transaction->product_name ?: 'Order',
                    'sku' => (string) $transaction->id,
                    'quantity' => 1,
                    'unit_price' => ['amount' => $amount, 'currency' => $currency],
                    'total_amount' => ['amount' => $amount, 'currency' => $currency],
                ]],
                'consumer' => [
                    'first_name' => $buyer['name'],
                    'last_name' => '.',
                    'phone_number' => $buyer['phone'],
                    'email' => $buyer['email'],
                ],
                'merchant_url' => [
                    'success' => route('ipn', 'tamara'),
                    'failure' => route('ipn', 'tamara'),
                    'cancel' => route('ipn', 'tamara'),
                    'notification' => route('webhook', 'tamara'),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[tamara] checkout failed: '.$e->getMessage());
            app_alert_error(___('The payment service is unavailable right now. Please try again.'));

            return redirect($cancelUrl);
        }

        $url = $resp->json('checkout_url');
        $tamaraOrderId = $resp->json('order_id');
        if ($resp->successful() && is_string($url) && $url !== '' && is_scalar($tamaraOrderId) && (string) $tamaraOrderId !== '') {
            // WI-06e: the notification may only authorise, capture and settle the order created for this transaction.
            $details = (array) ($transaction->details ?? []);
            $details['tamara_order_id'] = (string) $tamaraOrderId;
            $transaction->forceFill(['details' => $details])->save();

            return redirect()->away($url);
        }

        Log::warning('[tamara] checkout rejected: '.$resp->status());
        app_alert_error(___('Could not start the payment. Please try again.'));

        return redirect($cancelUrl);
    }

    /** Customer return — display-only, never settles. */
    public function handleReturn(Request $request): Response
    {
        $ref = $request->input('order_reference_id') ?: $request->input('orderId');

        return Settlement::confirmingForBuyer(is_scalar($ref) && (string) $ref !== '' ? Transaction::find($ref) : null);
    }

    public function handleWebhook(Request $request): Response
    {
        $jwt = $request->header('tamaraToken') ?: $request->header('tamaratoken') ?: $request->input('tamaraToken');
        if (! is_string($jwt) || $jwt === '') {
            return response('missing token', 401);
        }

        // Peek at the (unverified) payload / body ONLY to pick the account whose Notification Token verifies it.
        $claims = $this->decodePayloadUnsafe($jwt) ?? [];
        $orderRef = $claims['order_reference_id'] ?? $request->input('order_reference_id');
        if (! is_scalar($orderRef) || (string) $orderRef === '') {
            return response('missing ref', 401);
        }

        $candidate = Transaction::find((string) $orderRef);
        if ($candidate === null) {
            return response('ok', 200); // unknown: no side effect possible
        }

        $creds = $this->creds($candidate);
        if ($creds['notification_token'] === '') {
            return response('unverified', 401);
        }
        if (! $this->verifyHs256($jwt, $creds['notification_token'])) {
            Log::warning('[tamara] JWT signature verification failed');

            return response('invalid', 401);
        }

        // WI-06e: the claims are verified now. An expired token is refused; the body may not contradict the token.
        if (array_key_exists('exp', $claims) && (! is_numeric($claims['exp']) || (int) $claims['exp'] < time())) {
            Log::warning("[tamara] expired notification token for tx {$candidate->id}");

            return response('expired', 401);
        }
        foreach (['order_reference_id', 'order_id', 'event_type'] as $field) {
            if (array_key_exists($field, $claims) && $request->filled($field) && (string) $request->input($field) !== (string) $claims[$field]) {
                Log::warning("[tamara] notice {$field} differs from the signed token for tx {$candidate->id}");

                return response('token mismatch', 401);
            }
        }

        $eventType = $claims['event_type'] ?? $request->input('event_type');
        $orderId = $claims['order_id'] ?? $request->input('order_id');
        if (! in_array($eventType, ['order_approved', 'order_authorised'], true)) {
            return response('ignored', 200); // declined / expired / canceled → nothing to settle
        }
        if (! is_scalar($orderId) || (string) $orderId === '') {
            return response('missing order_id', 200);
        }
        $orderId = (string) $orderId;
        if ($candidate->status !== null) {
            return response('ok', 200); // already settled: nothing to authorise or capture again
        }

        // WI-06e: the Tamara order must be the one created for this transaction at initiate.
        $expectedOrderId = (string) ($candidate->details->tamara_order_id ?? '');
        if ($expectedOrderId === '' || ! hash_equals($expectedOrderId, $orderId)) {
            Log::warning("[tamara] order {$orderId} does not belong to tx {$candidate->id}");

            return response('order mismatch', 200);
        }

        $currency = $this->currency($candidate);
        try {
            // AUTHORISE (required or the money releases), then CAPTURE the full total.
            $auth = Http::withToken($creds['api_token'])->acceptJson()->post($creds['base'].'/orders/'.rawurlencode($orderId).'/authorise', []);
            if (! $auth->successful()) {
                Log::warning('[tamara] authorise rejected: '.$auth->status());

                return response('authorise failed', 200);
            }
            // Capture the SAME currency-aware total we authorised; `shipping_info` is required by the capture API.
            $amount = GatewayMinorUnits::majorFloatFromMoney($candidate->money('amount'), $currency);
            $capture = Http::withToken($creds['api_token'])->acceptJson()->post($creds['base'].'/orders/'.rawurlencode($orderId).'/capture', [
                'order_id' => $orderId,
                'total_amount' => ['amount' => $amount, 'currency' => $currency],
                'shipping_info' => [
                    'shipped_at' => now()->toIso8601String(),
                    'shipping_company' => $this->shippingCompany($candidate),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[tamara] authorise/capture failed: '.$e->getMessage());

            return response('capture error', 200);
        }
        if (! $capture->successful()) {
            Log::warning('[tamara] capture rejected: '.$capture->status());

            return response('capture failed', 200);
        }

        // Only a full capture (`captured_amount`, else the older `total_amount`) of EXACTLY our amount in our currency.
        $captured = $capture->json('captured_amount') ?? $capture->json('total_amount');
        $capturedAmount = is_array($captured) ? ($captured['amount'] ?? null) : null;
        $capturedCurrency = is_array($captured) ? strtoupper((string) ($captured['currency'] ?? '')) : '';
        $status = $capture->json('status');
        if (($status !== null && $status !== 'fully_captured')
            || ($capturedCurrency !== '' && $capturedCurrency !== $currency)
            || ($capturedAmount !== null && ! GatewayMinorUnits::paidMatches('tamara', $capturedAmount, $candidate->money('amount'), $currency))) {
            $shown = is_scalar($capturedAmount) ? (string) $capturedAmount : '-';
            Log::warning("[tamara] capture does not match tx {$candidate->id}: captured {$shown} {$capturedCurrency}, status ".(is_scalar($status) ? $status : '-'));

            return response('amount mismatch', 200);
        }

        $transaction = Transaction::where([['id', $candidate->id], ['status', null], ['payment_id', null]])->first();
        if ($transaction === null) {
            return response('ok', 200); // already settled / replay
        }

        // J25-7: "tamara confirmed this payment paid" — a repeated or concurrent confirmation is a no-op.
        return WebhookReceipts::once('tamara', WebhookReceipts::key($transaction->id, 'paid'), function () use ($transaction) {
            Settlement::settle($transaction, 'tamara', null, claim: true);

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

    /** Who delivers it, for the capture's required `shipping_info.shipping_company`: the store, else the platform. */
    private function shippingCompany(Transaction $transaction): string
    {
        $store = Settlement::store($transaction);
        $name = trim((string) ($store->title ?? config('app.name', '')));

        return $name !== '' ? mb_substr($name, 0, 100) : 'Merchant';
    }

    /**
     * Constant-time HS256 JWT verification over the raw `header.payload` (firebase/php-jwt 7.x rejects short keys).
     * The header must declare HS256 (no alg=none / algorithm confusion).
     */
    private function verifyHs256(string $jwt, string $secret): bool
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || $secret === '') {
            return false;
        }
        [$header, $payload, $signature] = $parts;
        $headerJson = base64_decode(strtr($header, '-_', '+/'), true);
        $headerArr = $headerJson === false ? null : json_decode($headerJson, true);
        if (! is_array($headerArr) || ($headerArr['alg'] ?? null) !== 'HS256') {
            return false;
        }
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$payload, $secret, true)), '+/', '-_'), '=');

        return hash_equals($expected, $signature);
    }

    /**
     * A JWT payload WITHOUT verifying — account-selection hint only.
     *
     * @return array<string, mixed>|null
     */
    private function decodePayloadUnsafe(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /** @return array{api_token: string, notification_token: string, base: string, sandbox: bool} */
    private function creds(Transaction $transaction): array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $opts = store_options($transaction->store_id);
            [$api, $notify] = [$opts->restaurant_tamara_api_token ?? null, $opts->restaurant_tamara_notification_token ?? null];
            $sandbox = gateway_is_sandbox($opts->restaurant_tamara_sandbox_mode ?? null);
        } else {
            [$api, $notify] = [config('settings.tamara_api_token'), config('settings.tamara_notification_token')];
            $sandbox = gateway_is_sandbox(config('settings.tamara_sandbox', true));
        }

        return GatewaySandbox::gate('tamara', $sandbox, [
            'api_token' => is_scalar($api) ? (string) $api : '',
            'notification_token' => is_scalar($notify) ? (string) $notify : '',
            'base' => $sandbox ? self::BASE_SANDBOX : self::BASE_LIVE,
            'sandbox' => $sandbox,
        ]);
    }
}
