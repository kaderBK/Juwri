<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\AccountPages;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * G6 Fawry (atfawry.com) — Egypt: cards (incl. Meeza), wallets, PayAtFawry cash-at-kiosk, ValU. EGP only.
 * P1-23 part 6: moved from the hand-written FawryController into the adapter family, same wire protocol — the routes,
 * the return URL `ipn/fawry`, the webhook `webhook/fawry` and our id as `merchantRefNum` are unchanged, so a charge
 * opened by the old controller still settles here.
 *
 * No API-key auth: every request and notification carries a SHA-256 concat signature over merchantCode + fields +
 * secureKey (the 2-decimal formatting and the empty-string placeholders are load-bearing — see the sign helpers).
 *
 * initiate: a signed hosted ("Express Checkout") charge; the buyer goes to the returned URL.
 * handleWebhook (Server-to-Server Notification V2): the transaction named by OUR merchantRefNum is loaded first and the
 * messageSignature is recomputed with THAT transaction's secureKey — the STORE's for orders and store payments (gift
 * cards, top-ups, deposits; {@see Settlement::usesStoreGateway()}), the platform's for plans/add-ons; bad signature =
 * 401, no side effects. Only orderStatus PAID settles (cash-at-kiosk NEW/UNPAID is acknowledged and stays pending), for
 * the exact amount in EGP, once (WebhookReceipts + claim).
 * handleReturn: display-only. No refund API wired (the old controller had none): tracked manual refund.
 */
class FawryAdapter implements GatewayAdapter
{
    private const BASE_SANDBOX = 'https://atfawry.fawrystaging.com';

    private const BASE_LIVE = 'https://atfawry.com';

    /** Fawry charges in Egyptian pounds only. */
    private const CURRENCY = 'EGP';

    public function key(): string
    {
        return 'fawry';
    }

    public function initiate(Transaction $transaction): Response
    {
        $cancelUrl = Settlement::cancelUrl($transaction);
        $buyer = GatewayBuyer::of($transaction);

        $creds = $this->creds($transaction);
        if ($creds['merchant_code'] === '' || $creds['security_key'] === '') {
            app_alert_error(___('This payment method is not configured.'));

            return redirect($cancelUrl);
        }

        // WI-06d: charge in the transaction's own currency, which Fawry only accepts as EGP. Anything else would be
        // charged as the same number of pounds and credited in the other currency.
        $currency = $this->currencyOf($transaction);
        if ($currency !== self::CURRENCY) {
            Log::warning("[fawry] tx {$transaction->id} is in {$currency}; Fawry charges EGP only");
            app_alert_error(___('This payment method only accepts payments in Egyptian pounds (EGP).'));

            return redirect($cancelUrl);
        }

        $merchantRefNum = (string) $transaction->id;
        $returnUrl = route('ipn', 'fawry');
        $itemId = '1';
        $quantity = '1';
        $price = (float) $transaction->amount;

        $payload = [
            'merchantCode' => $creds['merchant_code'],
            'merchantRefNum' => $merchantRefNum,
            'customerName' => $buyer['name'],
            'customerMobile' => $buyer['phone'],
            'customerEmail' => $buyer['email'],
            'paymentExpiry' => (int) (now()->addDay()->timestamp * 1000),
            'chargeItems' => [[
                'itemId' => $itemId,
                'description' => $transaction->product_name ?: 'Order',
                'price' => (float) self::money($price),
                'quantity' => (int) $quantity,
            ]],
            'returnUrl' => $returnUrl,
            'currencyCode' => $currency,
            'signature' => self::signCheckout($creds['merchant_code'], $merchantRefNum, '', $returnUrl, $itemId, $quantity, $price, $creds['security_key']),
        ];

        try {
            $resp = Http::acceptJson()->post($creds['base'].'/ECommerceWeb/api/payments/charge', $payload);
        } catch (\Throwable $e) {
            Log::warning('[fawry] charge failed: '.$e->getMessage());
            app_alert_error(___('The payment service is unavailable right now. Please try again.'));

            return redirect($cancelUrl);
        }

        $json = $resp->json();
        $json = is_array($json) ? $json : [];
        // The hosted Express Checkout URL: `nextAction.redirectUrl`, or a bare `redirectUrl` / `url`.
        $url = data_get($json, 'nextAction.redirectUrl') ?? ($json['redirectUrl'] ?? ($json['url'] ?? null));
        if ($resp->successful() && is_string($url) && $url !== '') {
            return redirect()->away($url);
        }

        Log::warning('[fawry] charge rejected: '.$resp->status().' '.json_encode($json));
        app_alert_error(___('Could not start the payment. Please try again.'));

        return redirect($cancelUrl);
    }

    /** Customer return — display-only, never settles (the notification does). */
    public function handleReturn(Request $request): Response
    {
        $ref = $request->input('merchantRefNumber') ?: $request->input('merchantRefNum');
        $transaction = is_scalar($ref) && (string) $ref !== '' ? Transaction::find($ref) : null;

        if ($transaction !== null && $transaction->status === Transaction::STATUS_SUCCESS) {
            app_alert_success(___('Payment successful'));
        } else {
            // Cash-at-kiosk buyers land here with a reference code but no payment yet.
            app_alert_info(___('We are confirming your payment. If you chose Pay at Fawry, use your reference code at any Fawry outlet — your order updates once paid.'));
        }
        $store = $transaction !== null ? Settlement::store($transaction) : null;

        return redirect($store !== null ? route('publicView', $store->slug) : AccountPages::url());
    }

    /** Server-to-Server Notification V2 — settles ONLY after the messageSignature, PAID and the exact amount. */
    public function handleWebhook(Request $request): Response
    {
        $field = static fn (string ...$keys): string => (string) collect($keys)
            ->map(fn (string $key) => $request->input($key))
            ->first(fn ($value) => is_scalar($value) && (string) $value !== '', '');
        $merchantRefNum = $field('merchantRefNum', 'merchantRefNumber');
        $fawryRefNumber = $field('fawryRefNumber');
        $paymentAmount = $request->input('paymentAmount');
        $orderAmount = $request->input('orderAmount');
        $orderStatus = $field('orderStatus');
        $paymentMethod = $field('paymentMethod');
        $paymentRefNumber = $field('paymentRefNumber');
        $provided = $field('messageSignature');

        if ($merchantRefNum === '') {
            return response('missing ref', 200);
        }

        // The transaction OUR ref names picks the account whose secureKey must have signed the notification.
        $probe = ctype_digit($merchantRefNum) ? Transaction::find($merchantRefNum) : null;
        $creds = $probe !== null ? $this->creds($probe) : $this->platformCreds();
        if ($creds['security_key'] === '') {
            // Nothing to verify against (no key on that account): acknowledge so Fawry stops retrying; never settles.
            return response('ok', 200);
        }

        $expected = self::signWebhook($fawryRefNumber, $merchantRefNum, $paymentAmount, $orderAmount, $orderStatus, $paymentMethod, $paymentRefNumber, $creds['security_key']);
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            Log::warning("[fawry] bad messageSignature for ref {$merchantRefNum}");

            return response('invalid signature', 401);
        }

        // J25-7: once per verified event: a re-delivered or concurrent copy is a 200 no-op.
        $event = WebhookReceipts::key($fawryRefNumber !== '' ? $fawryRefNumber : $merchantRefNum, $orderStatus, WebhookReceipts::fingerprint($request));

        return WebhookReceipts::once('fawry', $event, function () use ($request, $orderStatus, $merchantRefNum, $paymentAmount) {
            // Cash-at-kiosk (any not-yet-paid state): acknowledged, NOT settled — it stays pending until a PAID follows.
            if ($orderStatus !== 'PAID') {
                return response('ok', 200);
            }

            $transaction = Transaction::where([['id', $merchantRefNum], ['status', null], ['payment_id', null]])->first();
            if ($transaction === null) {
                return response('ok', 200); // unknown or already settled (replay)
            }

            // WI-06d: the transaction must be in EGP, and a reported currency code must agree.
            $reported = strtoupper(trim((string) ($request->input('currencyCode') ?? $request->input('currency') ?? '')));
            if ($this->currencyOf($transaction) !== self::CURRENCY || ($reported !== '' && $reported !== self::CURRENCY)) {
                Log::warning("[fawry] currency mismatch tx {$transaction->id}");

                return response('currency mismatch', 200);
            }
            // Exact at Fawry's 2 decimals, in EGP (Settlement::amountMatches logs a mismatch).
            $paid = is_int($paymentAmount) || is_float($paymentAmount) || is_string($paymentAmount) ? $paymentAmount : '';
            if (! Settlement::amountMatches($transaction, $paid, $reported !== '' ? $reported : null, 'fawry', self::CURRENCY)) {
                return response('amount mismatch', 200);
            }

            // J25-7: claim + durable credit (outbox) — settles only a still-pending row.
            Settlement::settle($transaction, 'fawry', null, claim: true);

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

    /* ------------------------------------------------------------ signatures */

    /** EGP at Fawry's fixed 2 decimals ({@see GatewayMinorUnits::GATEWAY_MAX_DECIMALS}), dot-separated, no thousands — the format Fawry hashes. */
    private static function money(mixed $amount): string
    {
        return number_format(is_numeric($amount) ? (float) $amount : 0.0, GatewayMinorUnits::gatewayDecimals('fawry', 'EGP'), '.', '');
    }

    /**
     * Charge request: SHA-256(merchantCode + merchantRefNum + customerProfileId("" if none) + returnUrl + itemId +
     * quantity + price(2dp) + secureKey) — single item.
     */
    public static function signCheckout(string $merchantCode, string $merchantRefNum, string $customerProfileId, string $returnUrl, string $itemId, string $quantity, mixed $price, string $secureKey): string
    {
        return hash('sha256', $merchantCode.$merchantRefNum.$customerProfileId.$returnUrl.$itemId.$quantity.self::money($price).$secureKey);
    }

    /**
     * Notification V2: SHA-256(fawryRefNumber + merchantRefNum + paymentAmount(2dp) + orderAmount(2dp) + orderStatus +
     * paymentMethod + paymentRefNumber("" on order-create) + secureKey).
     */
    public static function signWebhook(string $fawryRefNumber, string $merchantRefNum, mixed $paymentAmount, mixed $orderAmount, string $orderStatus, string $paymentMethod, string $paymentRefNumber, string $secureKey): string
    {
        return hash('sha256', $fawryRefNumber.$merchantRefNum.self::money($paymentAmount).self::money($orderAmount).$orderStatus.$paymentMethod.$paymentRefNumber.$secureKey);
    }

    /* ------------------------------------------------------------ internals */

    private function currencyOf(Transaction $transaction): string
    {
        return strtoupper(trim((string) $transaction->currency_code));
    }

    /**
     * The account a transaction is paid into: the STORE's for orders and store payments, the platform's otherwise.
     *
     * @return array{merchant_code: string, security_key: string, base: string}
     */
    private function creds(Transaction $transaction): array
    {
        if (! Settlement::usesStoreGateway($transaction)) {
            return $this->platformCreds();
        }
        $opts = store_options($transaction->store_id);

        return $this->shape($opts->restaurant_fawry_merchant_code ?? null, $opts->restaurant_fawry_security_key ?? null, gateway_is_sandbox($opts->restaurant_fawry_sandbox_mode ?? null));
    }

    /** @return array{merchant_code: string, security_key: string, base: string} */
    private function platformCreds(): array
    {
        return $this->shape(config('settings.fawry_merchant_code'), config('settings.fawry_security_key'), gateway_is_sandbox(config('settings.fawry_sandbox', true)));
    }

    /** @return array{merchant_code: string, security_key: string, base: string} */
    private function shape(mixed $merchant, mixed $secure, bool $sandbox): array
    {
        return GatewaySandbox::gate('fawry', $sandbox, [
            'merchant_code' => is_scalar($merchant) ? trim((string) $merchant) : '',
            'security_key' => is_scalar($secure) ? trim((string) $secure) : '',
            'base' => $sandbox ? self::BASE_SANDBOX : self::BASE_LIVE,
        ]);
    }
}
