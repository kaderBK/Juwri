<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayCurrencies;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * G2 Paymob (paymob.com) — MENA PSP, Intention API + Unified Checkout. P1-23 part 5: moved from the hand-written
 * PaymobController into the adapter family; same routes, notification/redirection URLs, HMAC and stored
 * `details.paymob_order_id`, so an intention created by the old controller still settles here.
 *
 * initiate: POST {base}/v1/intention/ (Authorization: Token <secret_key>) with the amount in CENTS (exact; 3-decimal
 * currencies refused, GatewayCurrencies::MAX_CURRENCY_DECIMALS) and our id as special_reference → Unified Checkout.
 * handleWebhook (transaction-processed callback): the candidate transaction is resolved from the echoed merchant order
 * id, its OWN HMAC secret verifies the HMAC-SHA512 over the fixed field list (bad → 401, no side effect); the signed
 * order.id must be the Paymob order stored at initiate (merchant_order_id is not signed), success true, amount and
 * currency exact; settles once.
 *
 * Keys: the STORE's for orders and store payments ({@see Settlement::usesStoreGateway()}), the platform's otherwise.
 * The return is display-only. No refund API wired.
 *
 * P6-108b — region hosts. A Paymob account lives on one regional host (accept.paymob.com = Egypt, ksa.paymob.com,
 * uae.paymob.com, oman.paymob.com); keys of one region do not work on another. The host is, in order: the explicit
 * `paymob_region` setting (store: restaurant_paymob_region; platform: settings.paymob_region), the store's country
 * (fiscal_country), the transaction currency, else KSA (the behaviour before P6-108b). The intention call and the
 * Unified Checkout redirect use the same host. The callback is unaffected: Paymob posts it to OUR webhook URL and the
 * HMAC-SHA512 over the same field list is region-independent (one code path for every region).
 */
class PaymobAdapter implements GatewayAdapter
{
    /** Region => host. Keys are what the region setting stores. */
    public const HOSTS = [
        'eg' => 'https://accept.paymob.com',
        'ksa' => 'https://ksa.paymob.com',
        'uae' => 'https://uae.paymob.com',
        'omn' => 'https://oman.paymob.com',
    ];

    /** The region when nothing names one (pre-P6-108b behaviour). */
    public const DEFAULT_REGION = 'ksa';

    private const COUNTRY_REGION = ['EG' => 'eg', 'SA' => 'ksa', 'AE' => 'uae', 'OM' => 'omn'];

    private const CURRENCY_REGION = ['EGP' => 'eg', 'SAR' => 'ksa', 'AED' => 'uae', 'OMR' => 'omn'];

    /** The EXACT ordered field list Paymob concatenates for the HMAC-SHA512. */
    public const HMAC_FIELDS = [
        'amount_cents', 'created_at', 'currency', 'error_occured',
        'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
        'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
        'is_voided', 'order.id', 'owner', 'pending', 'source_data.pan',
        'source_data.sub_type', 'source_data.type', 'success',
    ];

    public function key(): string
    {
        return 'paymob';
    }

    public function initiate(Transaction $transaction): Response
    {
        if (($invalid = GatewayBuyer::requireOrderContact($transaction)) !== null) {
            return $invalid;
        }
        $cancelUrl = Settlement::cancelUrl($transaction);
        $buyer = GatewayBuyer::of($transaction);

        $creds = $this->creds($transaction);
        if ($creds['secret'] === '' || $creds['public'] === '' || $creds['integration_id'] === '') {
            app_alert_error(___('This payment method is not configured.'));

            return redirect($cancelUrl);
        }

        // The amount at Paymob's own decimals, or a refusal — never rounded.
        $currency = $this->currency($transaction);
        [$amount, $refusal] = GatewayCurrencies::amountOrRefusal('paymob', $transaction, $currency);
        if ($amount === null) {
            app_alert_error((string) $refusal);

            return redirect($cancelUrl);
        }
        $cents = BigDecimal::of($amount)->withPointMovedRight(2)->toInt(); // amount_cents (×100), exact

        $base = self::HOSTS[$this->region($transaction)];
        try {
            $resp = Http::withHeaders(['Authorization' => 'Token '.$creds['secret']])->acceptJson()
                ->post($base.'/v1/intention/', [
                    'amount' => $cents,
                    'currency' => $currency,
                    'payment_methods' => [(int) $creds['integration_id']],
                    'special_reference' => (string) $transaction->id,
                    'notification_url' => route('webhook', 'paymob'),
                    'redirection_url' => route('ipn', 'paymob'),
                    'items' => [[
                        'name' => $transaction->product_name ?: 'Order',
                        'amount' => $cents,
                        'quantity' => 1,
                    ]],
                    'billing_data' => [
                        'first_name' => $buyer['name'],
                        'last_name' => 'NA',
                        'email' => $buyer['email'] !== '' ? $buyer['email'] : 'customer@example.com',
                        'phone_number' => $buyer['phone'] !== '' ? $buyer['phone'] : 'NA',
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('[paymob] intention failed: '.$e->getMessage());
            app_alert_error(___('The payment service is unavailable right now. Please try again.'));

            return redirect($cancelUrl);
        }

        $clientSecret = $resp->json('client_secret');
        $paymobOrderId = $resp->json('intention_order_id');
        if ($resp->successful() && is_string($clientSecret) && $clientSecret !== '' && is_scalar($paymobOrderId) && (string) $paymobOrderId !== '') {
            // WI-06d: remember Paymob's order id — it is inside the signed callback fields (order.id).
            $details = (array) ($transaction->details ?? []);
            $details['paymob_order_id'] = (string) $paymobOrderId;
            $transaction->forceFill(['details' => $details])->save();

            return redirect()->away($base.'/unifiedcheckout/?publicKey='.urlencode($creds['public']).'&clientSecret='.urlencode($clientSecret));
        }

        Log::warning('[paymob] intention rejected: '.$resp->status());
        app_alert_error(___('Could not start the payment. Please try again.'));

        return redirect($cancelUrl);
    }

    /** Customer return (redirection_url) — display-only, never settles. */
    public function handleReturn(Request $request): Response
    {
        $ref = $request->input('merchant_order_id') ?? $request->input('order') ?? $request->input('special_reference');

        return Settlement::confirmingForBuyer(is_scalar($ref) && (string) $ref !== '' ? Transaction::find($ref) : null);
    }

    public function handleWebhook(Request $request): Response
    {
        $obj = $request->input('obj', []);
        if (! is_array($obj)) {
            $obj = [];
        }

        // Our Transaction id = the special_reference echoed as order.merchant_order_id.
        $merchantOrderId = data_get($obj, 'order.merchant_order_id') ?? data_get($obj, 'order.merchant_order_id_str') ?? $request->input('merchant_order_id');
        if (! is_scalar($merchantOrderId) || (string) $merchantOrderId === '') {
            return response('ok', 200); // nothing to resolve — noop
        }

        $transaction = Transaction::where([['id', (string) $merchantOrderId], ['status', null], ['payment_id', null]])->first();
        if ($transaction === null) {
            return response('ok', 200); // unknown or already settled — noop
        }

        // Verify HMAC-SHA512 with THIS transaction's account secret before ANY side effect.
        $expected = $this->computeHmac($obj, $this->creds($transaction)['hmac']);
        $provided = $request->query('hmac', $request->input('hmac', ''));
        $provided = is_string($provided) ? $provided : '';
        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response('invalid signature', 401);
        }

        // J25-7: once per verified event (WebhookReceipts): a re-delivered or concurrent copy is a 200 no-op.
        return WebhookReceipts::once('paymob', WebhookReceipts::key(data_get($obj, 'id'), json_encode(data_get($obj, 'success')), WebhookReceipts::fingerprint($request)), function () use ($transaction, $obj) {
            // WI-06d: merchant_order_id is not signed — bind to the signed order.id recorded at initiate.
            $expectedOrderId = (string) ($transaction->details->paymob_order_id ?? '');
            $signedOrderId = data_get($obj, 'order.id');
            if ($expectedOrderId === '' || ! is_scalar($signedOrderId) || (string) $signedOrderId !== $expectedOrderId) {
                Log::warning("[paymob] order id does not belong to tx {$transaction->id}");

                return response('order mismatch', 200);
            }

            $success = data_get($obj, 'success');
            if ($success !== true && $success !== 'true' && $success !== 1 && $success !== '1') {
                return response('not successful', 200);
            }

            // Exact, in cents; a currency Paymob cannot charge here (3 decimals) never settles.
            $ourCurr = $this->currency($transaction);
            $cents = data_get($obj, 'amount_cents');
            $paidAmount = is_numeric($cents) ? (string) BigDecimal::of((string) $cents)->withPointMovedLeft(2) : null;
            if (! GatewayCurrencies::canCharge('paymob', $transaction)
                || ! GatewayMinorUnits::paidMatches('paymob', $paidAmount, $transaction->money('amount'), $ourCurr)) {
                Log::warning("[paymob] amount mismatch tx {$transaction->id}: paid ".($paidAmount ?? '-'));

                return response('amount mismatch', 200);
            }
            $paidCurr = data_get($obj, 'currency', '');
            $paidCurr = strtoupper(is_scalar($paidCurr) ? (string) $paidCurr : '');
            if ($paidCurr !== '' && $paidCurr !== $ourCurr) {
                Log::warning("[paymob] currency mismatch tx {$transaction->id}: {$paidCurr} vs {$ourCurr}");

                return response('currency mismatch', 200);
            }

            // J25-7: mark paid + credit message in one DB transaction; the credit runs through the outbox (retried).
            Settlement::settle($transaction, 'paymob', null, claim: true);

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

    /**
     * The Paymob region for this transaction: explicit setting, then store country, then currency, then KSA.
     * An unknown explicit value is ignored (falls through), never sent to an arbitrary host.
     */
    public function region(Transaction $transaction): string
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $setting = store_options($transaction->store_id, 'restaurant_paymob_region');
            $country = store_options($transaction->store_id, 'fiscal_country');
        } else {
            $setting = config('settings.paymob_region');
            $country = null;
        }

        return self::resolveRegion(
            is_scalar($setting) ? (string) $setting : null,
            is_scalar($country) ? (string) $country : null,
            $this->currency($transaction),
        );
    }

    public static function resolveRegion(?string $setting, ?string $country, ?string $currency): string
    {
        $setting = strtolower(trim((string) $setting));
        if (isset(self::HOSTS[$setting])) {
            return $setting;
        }

        return self::COUNTRY_REGION[strtoupper(trim((string) $country))]
            ?? self::CURRENCY_REGION[strtoupper(trim((string) $currency))]
            ?? self::DEFAULT_REGION;
    }

    /* ------------------------------------------------------------ internals */

    private function currency(Transaction $transaction): string
    {
        return strtoupper((string) ($transaction->currency_code ?: 'SAR'));
    }

    /** @param  array<mixed>  $obj */
    private function computeHmac(array $obj, string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        $concat = '';
        foreach (self::HMAC_FIELDS as $field) {
            $value = data_get($obj, $field);
            // Paymob concatenation casting: booleans as 'true'/'false', null as ''.
            $concat .= is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : '');
        }

        return hash_hmac('sha512', $concat, $secret);
    }

    /** @return array{secret: string, public: string, integration_id: string, hmac: string, sandbox: bool} */
    private function creds(Transaction $transaction): array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $opts = store_options($transaction->store_id);
            $values = [$opts->restaurant_paymob_secret_key ?? null, $opts->restaurant_paymob_public_key ?? null, $opts->restaurant_paymob_integration_id ?? null, $opts->restaurant_paymob_hmac_secret ?? null];
            $sandbox = gateway_is_sandbox($opts->restaurant_paymob_sandbox_mode ?? null);
        } else {
            $values = [config('settings.paymob_secret_key'), config('settings.paymob_public_key'), config('settings.paymob_integration_id'), config('settings.paymob_hmac_secret')];
            $sandbox = gateway_is_sandbox(config('settings.paymob_sandbox', true));
        }
        [$secret, $public, $integration, $hmac] = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $values);

        return GatewaySandbox::gate('paymob', $sandbox, ['secret' => $secret, 'public' => $public, 'integration_id' => $integration, 'hmac' => $hmac, 'sandbox' => $sandbox]);
    }
}
