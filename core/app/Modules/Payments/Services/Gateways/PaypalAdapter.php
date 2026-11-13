<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Contracts\OrderPaymentHandler;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\AccountPages;
use Juwri\Modules\Payments\Support\Fulfilment;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * PayPal REST (https://developer.paypal.com/docs/api/): Orders v2 for one-time
 * payments, Catalog Products + Billing Plans + Subscriptions for recurring
 * plans and monthly add-ons. OAuth2 client-credentials token per request.
 *
 * One-time: create an order (intent CAPTURE), send the buyer to its approve
 * link, capture on return (`ipn/paypal?pay_mode=one_time&token=<order id>`)
 * and settle when the capture is COMPLETED for the amount we asked.
 * Recurring: the transaction's attributes travel as the subscription's
 * `custom_id`; each `PAYMENT.SALE.COMPLETED` webhook re-reads the subscription
 * from PayPal (the body itself is never trusted for money) and settles through
 * CheckoutController::processWebhook. Every notification must pass PayPal's
 * verify-webhook-signature check with the configured webhook id
 * (`settings.paypal_webhook_id`; none configured = every notification refused)
 * and is processed once per event id (WebhookReceipts). A one-time order whose
 * buyer never returns settles from CHECKOUT.ORDER.APPROVED (captured here) or
 * PAYMENT.CAPTURE.COMPLETED, re-read from PayPal, claimed once.
 *
 * Credentials: store orders use the store's own app (BYOK), everything else the
 * platform app. `…_sandbox_mode = Yes` selects the sandbox host — only while
 * payment sandboxes are allowed ({@see GatewaySandbox}).
 */
class PaypalAdapter implements AuthorisesPayments, GatewayAdapter, StoreWebhooks
{
    public const LIVE = 'https://api-m.paypal.com';

    public const SANDBOX = 'https://api-m.sandbox.paypal.com';

    /** @var array<string, string> access tokens by client id for this request */
    private array $tokens = [];

    public function key(): string
    {
        return 'paypal';
    }

    public function initiate(Transaction $transaction): Response
    {
        try {
            $credentials = $this->credentials($transaction);

            $url = $this->payMode($transaction) === 'recurring'
                ? $this->startSubscription($transaction, $credentials)
                : $this->startOrder($transaction, $credentials);

            return $url === null ? back() : redirect()->away($url);
        } catch (\Throwable $e) {
            PaymentLog::failure('paypal', $e, ['transaction_id' => $transaction->id]);
            app_alert_error($this->errorMessage($e));

            return back();
        }
    }

    public function handleReturn(Request $request): Response
    {
        if ($request->input('pay_mode') !== 'one_time') {
            // Subscriptions are credited by the PAYMENT.SALE.COMPLETED webhook.
            app_alert_success(___('Payment successful'));

            return redirect()->to(AccountPages::url());
        }

        $orderId = (string) $request->input('token', '');
        $transaction = $orderId === '' ? null : Settlement::pending(['payment_id' => $orderId]);
        if ($transaction === null) {
            return Settlement::invalid();
        }

        try {
            if (self::holdsCard($transaction)) {
                // P3-09: a weighed basket's card hold — authorise the order now, capture the weighed amount later
                return $this->authoriseOrder($transaction, $orderId) || (string) $transaction->fresh()?->status === CardHolds::STATUS_AUTHORISED
                    ? response(app(OrderPaymentHandler::class)->buyerPage($transaction->fresh() ?? $transaction))
                    : Settlement::failed();
            }
            $capture = $this->captureOrRead($transaction, $orderId);

            if (! $this->captureSettles($capture, $transaction)) {
                $fresh = $transaction->fresh();

                // Phase 4 item 18: the CHECKOUT.ORDER.APPROVED webhook may have captured and settled it first.
                return $fresh !== null && $fresh->status === Transaction::STATUS_SUCCESS
                    ? Settlement::completeForBuyer($fresh)
                    : Settlement::failed();
            }

            // J25-7: credit durable via the outbox, same answer to the buyer. Claimed: a webhook racing the return
            // settles it once.
            return Settlement::settleForBuyer($transaction, 'paypal', $orderId, claim: true)
                ?? Settlement::completeForBuyer($transaction->fresh() ?? $transaction);
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('paypal', $e);

            return Settlement::failed();
        }
    }

    public function handleWebhook(Request $request): Response
    {
        $event = json_decode($request->getContent(), true);
        if (! is_array($event)) {
            PaymentLog::webhookRejected('paypal', 'invalid payload');

            return $this->answer(400, 'Invalid payload.'); // before: 200 for anything unreadable
        }

        // Phase 4 item 18: every notification must pass PayPal's signature check. before: with no webhook id
        // configured the notification was processed unsigned (only the sale re-read guarded the money path).
        $platform = $this->credentials(null);
        $webhookId = (string) config('settings.paypal_webhook_id', '');
        if ($webhookId === '') {
            PaymentLog::webhookRejected('paypal', 'no webhook id configured (unsigned notifications are refused)');

            return $this->answer(400, 'PayPal webhook id not configured.');
        }
        if (! $this->signatureIsValid($request, $event, $webhookId, $platform)) {
            PaymentLog::webhookRejected('paypal', 'invalid signature');

            return $this->answer(400, 'Invalid PayPal signature.');
        }

        // J25-7 / Phase 4 item 18: once per verified event id — a replayed or re-delivered copy is a 200 no-op.
        return WebhookReceipts::once('paypal', WebhookReceipts::key($event['id'] ?? null), fn () => match ($event['event_type'] ?? null) {
            'PAYMENT.SALE.COMPLETED' => $this->saleCompleted($event, $platform),
            // A one-time order whose buyer never came back: approved (we capture it) or captured (we settle it).
            'CHECKOUT.ORDER.APPROVED' => $this->orderNotified((string) data_get($event, 'resource.id', ''), true),
            'PAYMENT.CAPTURE.COMPLETED' => $this->orderNotified((string) data_get($event, 'resource.supplementary_data.related_ids.order_id', ''), false),
            default => $this->answer(200, 'successful'),
        }, fn () => $this->answer(200, 'duplicate'));
    }

    /**
     * A recurring renewal (PAYMENT.SALE.COMPLETED): the sale and the subscription are re-read from PayPal (the body is
     * never trusted for money) and settled through processWebhook.
     *
     * @param  array<string, mixed>  $event
     * @param  array{client_id:string, secret:string, sandbox:bool}  $platform
     */
    private function saleCompleted(array $event, array $platform): Response
    {
        try {
            $sale = $event['resource'] ?? [];
            $subscriptionId = (string) ($sale['billing_agreement_id'] ?? '');
            if ($subscriptionId === '' || empty($sale['id'])) {
                return response()->json(['status' => 400], 400);
            }

            // The sale as PayPal holds it. The notification body is not trusted for money even when signed: the
            // sale must exist, be completed and belong to the named subscription, otherwise a forged or misrouted
            // notification could renew for free.
            $client = $this->client($platform);
            $saleId = (string) $sale['id'];
            $held = $client->get('/v1/payments/sale/'.rawurlencode($saleId))->throw()->json();
            if (strtolower((string) ($held['state'] ?? '')) !== 'completed'
                || (string) ($held['billing_agreement_id'] ?? '') !== $subscriptionId
                || (string) ($held['id'] ?? '') !== $saleId) {
                PaymentLog::webhookRejected('paypal', 'sale not confirmed by PayPal');

                return response()->json(['status' => 400], 400);
            }

            // The subscription as PayPal holds it — its custom_id is what we set at checkout.
            $subscription = $client->get('/v1/billing/subscriptions/'.rawurlencode($subscriptionId))->throw()->json();
            parse_str((string) ($subscription['custom_id'] ?? ''), $metadata);
            if ($metadata === []) {
                return response()->json(['status' => 400], 400);
            }
            // The sale must be in the currency the subscription was sold in (the checkout transaction's).
            $sold = strtoupper(trim((string) ($metadata['currency_code'] ?? '')));
            $paid = strtoupper(trim((string) data_get($held, 'amount.currency', '')));
            if ($sold !== '' && $paid !== '' && $sold !== $paid) {
                PaymentLog::webhookRejected('paypal', 'sale currency mismatch', null, ['sale' => $saleId, 'paid' => $paid, 'expected' => $sold]);

                return response()->json(['status' => 400], 400);
            }
            // PayPal currency precision: the sale must be for the amount the subscription was sold at (the plan price
            // we created from it), exactly. before: only the currency was compared — a sale for another amount renewed.
            if (! $this->saleAmountMatches($held, $metadata)) {
                PaymentLog::webhookRejected('paypal', 'sale amount mismatch', null, ['sale' => $saleId, 'paid' => data_get($held, 'amount.total'), 'expected' => $metadata['amount'] ?? null]);

                return response()->json(['status' => 400], 400);
            }

            return Fulfilment::webhook('paypal', array_to_object($metadata), (string) $sale['id'], $subscriptionId);
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('paypal', $e);

            return $this->answer(400, $e->getMessage());
        }
    }

    /**
     * Phase 4 item 18: a one-time order settled from PayPal's notification when the buyer never came back. The order is
     * read (and, when only approved, captured) with the transaction's own account, then settles under the same checks
     * as the return — COMPLETED capture of exactly our amount and currency — claimed once.
     */
    private function orderNotified(string $orderId, bool $capture, ?int $storeId = null): Response
    {
        $transaction = $orderId === '' ? null : Settlement::pending(['payment_id' => $orderId] + ($storeId !== null ? ['store_id' => $storeId] : []));
        // The store route settles only that store's own payments (paid into the store's app).
        $scoped = $transaction !== null && ($storeId === null || Settlement::usesStoreGateway($transaction));
        if ($transaction === null || ! $scoped || self::holdsCard($transaction)) {
            return $this->answer(200, 'nothing pending');
        }

        try {
            $order = $capture
                ? $this->captureOrRead($transaction, $orderId)
                : (array) $this->client($this->credentials($transaction))->get('/v2/checkout/orders/'.rawurlencode($orderId))->throw()->json();
            if (! $this->captureSettles($order, $transaction)) {
                return $this->answer(200, 'not settled');
            }
            Settlement::settle($transaction, 'paypal', $orderId, claim: true);
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('paypal', $e);

            return $this->answer(500, 'settlement failed'); // the receipt is released: PayPal's retry tries again
        }

        return $this->answer(200, 'successful');
    }

    /**
     * Capture the approved order; when PayPal refuses (already captured by the other path, declined) the order as PayPal
     * holds it — its captures say whether it was paid.
     *
     * @return array<string, mixed>
     */
    private function captureOrRead(Transaction $transaction, string $orderId): array
    {
        $client = $this->client($this->credentials($transaction));
        $capture = $client->withBody('{}', 'application/json')->post('/v2/checkout/orders/'.rawurlencode($orderId).'/capture');
        if ($capture->successful() && ($capture->json('status') === 'COMPLETED')) {
            return (array) $capture->json();
        }
        if ($capture->json('details.0.issue') === 'ORDER_ALREADY_CAPTURED') {
            return (array) $client->get('/v2/checkout/orders/'.rawurlencode($orderId))->json();
        }

        return (array) $capture->json();
    }

    /**
     * A store's OWN PayPal app notifying `webhook/paypal/store/{store}`: verified by PayPal against THAT store's webhook
     * id (`restaurant_paypal_webhook_id`) with that store's app credentials — never the platform's; no id = 400. Only
     * that store's own pending one-time payments settle (approved → captured here, or captured), once per event.
     */
    public function handleStoreWebhook(Request $request, int $storeId): Response
    {
        $event = json_decode($request->getContent(), true);
        $webhookId = trim((string) (store_options($storeId, 'restaurant_paypal_webhook_id') ?? ''));
        if (! is_array($event) || $webhookId === '' || ! $this->signatureIsValid($request, $event, $webhookId, $this->storeCredentials($storeId))) {
            PaymentLog::webhookRejected('paypal', 'invalid store signature', null, ['store_id' => $storeId]);

            return $this->answer(400, 'Invalid PayPal signature.');
        }

        return WebhookReceipts::once('paypal', WebhookReceipts::key('store', $storeId, $event['id'] ?? null), fn () => match ($event['event_type'] ?? null) {
            'CHECKOUT.ORDER.APPROVED' => $this->orderNotified((string) data_get($event, 'resource.id', ''), true, $storeId),
            'PAYMENT.CAPTURE.COMPLETED' => $this->orderNotified((string) data_get($event, 'resource.supplementary_data.related_ids.order_id', ''), false, $storeId),
            default => $this->answer(200, 'successful'),
        }, fn () => $this->answer(200, 'duplicate'));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        $own = $this->currency($transaction);

        // PayPal currency precision: HUF/TWD refunds carry PayPal's 0 decimals — a fractional forint refund is refused
        // by RefundGuard (before: sent as "100.50" and rejected by PayPal).
        return RefundGuard::run($transaction, $amount, $currency, $this->key(), GatewayMinorUnits::gatewayDecimals($this->key(), $own), function (Money $money, string $idempotencyKey) use ($transaction) {
            try {
                $client = $this->client($this->credentials($transaction));
                $order = $client->get('/v2/checkout/orders/'.rawurlencode((string) $transaction->payment_id))->throw()->json();
                $captureId = $order['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;
                if (! $captureId) {
                    return ['ok' => false, 'reference' => null, 'message' => 'No PayPal capture to refund.'];
                }

                $value = GatewayMinorUnits::gatewayMajorString($this->key(), $money);
                if ($value === null) {
                    return ['ok' => false, 'reference' => null, 'message' => (string) ___('PayPal cannot refund a fraction of a unit in :currency.', ['currency' => $money->currency()])];
                }
                // P6-80: PayPal-Request-Id = the ledger entry id — a retry returns the first refund, never a second.
                $refund = $client->withHeaders(['PayPal-Request-Id' => 'refund-'.$idempotencyKey])->post('/v2/payments/captures/'.rawurlencode($captureId).'/refund', [
                    'amount' => ['value' => $value, 'currency_code' => $money->currency()], // before: "500.00" for HUF 500
                ])->throw()->json();

                return ['ok' => true, 'reference' => $refund['id'] ?? null, 'message' => null];
            } catch (ConnectionException $e) {
                throw $e; // no answer: outcome unknown — RefundGuard keeps the reservation (bug batch 6)
            } catch (\Throwable $e) {
                return ['ok' => false, 'reference' => null, 'message' => $this->errorMessage($e)];
            }
        }, idempotent: true);
    }

    /** P3-09: capture part of the order's authorisation (final capture: the rest of the hold is released). */
    public function capture(Transaction $transaction, Money $amount): array
    {
        $authorization = (string) data_get($transaction->details, 'paypal_authorization_id', '');
        $value = GatewayMinorUnits::gatewayMajorString($this->key(), $amount);
        if ($authorization === '' || $value === null) {
            return ['ok' => false, 'reference' => null, 'message' => 'No PayPal authorisation to capture.'];
        }
        try {
            $capture = $this->client($this->credentials($transaction))
                ->post('/v2/payments/authorizations/'.rawurlencode($authorization).'/capture', [
                    'amount' => ['value' => $value, 'currency_code' => $amount->currency()],
                    'final_capture' => true,
                ])->throw()->json();
            if (! in_array($capture['status'] ?? null, ['COMPLETED', 'PENDING'], true)) {
                return ['ok' => false, 'reference' => null, 'message' => 'PayPal did not capture the payment.'];
            }

            return ['ok' => true, 'reference' => (string) ($capture['id'] ?? ''), 'message' => null];
        } catch (ConnectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ['ok' => false, 'reference' => null, 'message' => $this->errorMessage($e)];
        }
    }

    /** P3-09: void the order's authorisation (nothing charged). */
    public function void(Transaction $transaction): array
    {
        $authorization = (string) data_get($transaction->details, 'paypal_authorization_id', '');
        if ($authorization === '') {
            return ['ok' => false, 'reference' => null, 'message' => 'No PayPal authorisation to void.'];
        }
        try {
            $this->client($this->credentials($transaction))->withBody('{}', 'application/json')
                ->post('/v2/payments/authorizations/'.rawurlencode($authorization).'/void')->throw();

            return ['ok' => true, 'reference' => $authorization, 'message' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reference' => null, 'message' => $this->errorMessage($e)];
        }
    }

    /** P3-09: a store order that asks for a card hold (authorise now, capture the weighed amount later). */
    private static function holdsCard(Transaction $transaction): bool
    {
        return Settlement::usesStoreGateway($transaction) && data_get($transaction->details, 'capture') === 'manual'; // only store orders ask for a hold
    }

    /**
     * P3-09: authorise the approved order; the authorisation must be CREATED for exactly the amount and currency we
     * asked. Its id and expiry are kept on the transaction (PayPal honours an authorisation for 3 days, valid 29).
     */
    private function authoriseOrder(Transaction $transaction, string $orderId): bool
    {
        $result = $this->client($this->credentials($transaction))->withBody('{}', 'application/json')
            ->post('/v2/checkout/orders/'.rawurlencode($orderId).'/authorize')->throw()->json();
        $authorization = $result['purchase_units'][0]['payments']['authorizations'][0] ?? null;
        $currency = $this->currency($transaction);
        $price = $this->price($transaction, $currency);
        if (! is_array($authorization) || ($authorization['status'] ?? null) !== 'CREATED' || $price === null
            || strtoupper((string) ($authorization['amount']['currency_code'] ?? '')) !== $currency
            || ! is_numeric($authorization['amount']['value'] ?? null)
            || ! Money::decimal((string) $authorization['amount']['value'])->isEqualTo(Money::decimal($price))) {
            Log::warning("[paypal] authorisation of order {$orderId} does not match transaction {$transaction->id}");

            return false;
        }
        $expires = isset($authorization['expiration_time']) ? CarbonImmutable::parse((string) $authorization['expiration_time']) : now()->addDays(29);
        $details = (array) ($transaction->details ?? []);
        $details['paypal_authorization_id'] = (string) $authorization['id'];
        $transaction->forceFill(['details' => $details])->save();

        return Settlement::authorise($transaction, 'paypal', $orderId, $expires);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * @return array{client_id:string, secret:string, sandbox:bool}
     */
    private function credentials(?Transaction $transaction): array
    {
        if ($transaction !== null && Settlement::usesStoreGateway($transaction)) {
            return $this->storeCredentials((int) $transaction->store_id);
        }

        return self::allowed([
            'client_id' => (string) config('settings.paypal_api_client_id', ''),
            'secret' => (string) config('settings.paypal_api_secret', ''),
            'sandbox' => gateway_is_sandbox(config('settings.paypal_sandbox_mode'), false),
        ]);
    }

    /**
     * The store's own PayPal app (BYOK).
     *
     * @return array{client_id:string, secret:string, sandbox:bool}
     */
    private function storeCredentials(int $storeId): array
    {
        $options = store_options($storeId);

        return self::allowed([
            'client_id' => (string) ($options->restaurant_paypal_api_client_id ?? ''),
            'secret' => (string) ($options->restaurant_paypal_api_secret ?? ''),
            'sandbox' => gateway_is_sandbox($options->restaurant_paypal_sandbox_mode ?? null, false),
        ]);
    }

    /**
     * Phase 4 item 18: sandbox credentials count as "not configured" while payment sandboxes are switched off.
     *
     * @param  array{client_id:string, secret:string, sandbox:bool}  $credentials
     * @return array{client_id:string, secret:string, sandbox:bool}
     */
    private static function allowed(array $credentials): array
    {
        return GatewaySandbox::allowsMode('paypal', $credentials['sandbox'])
            ? $credentials
            : ['client_id' => '', 'secret' => '', 'sandbox' => true];
    }

    /** @param array{client_id:string, secret:string, sandbox:bool} $credentials */
    private function client(array $credentials): PendingRequest
    {
        if ($credentials['client_id'] === '' || $credentials['secret'] === '') {
            throw new RuntimeException(___('Payment failed, check the credentials.'));
        }

        $base = $credentials['sandbox'] ? self::SANDBOX : self::LIVE;
        $token = $this->tokens[$base.$credentials['client_id']] ??= (string) Http::baseUrl($base)
            ->withBasicAuth($credentials['client_id'], $credentials['secret'])
            ->asForm()->acceptJson()->timeout(30)
            ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->throw()->json('access_token');

        return Http::baseUrl($base)->withToken($token)->asJson()->acceptJson()->timeout(30);
    }

    /** The transaction's own currency (gateway-currency bug fix: never the store's/platform's current one). */
    private function currency(Transaction $transaction): string
    {
        return Settlement::currency($transaction, 'USD');
    }

    /**
     * The transaction's amount as PayPal wants it: a decimal string at the gateway's minor unit, dot separator. Bug
     * batch 10 · 2: through GatewayMinorUnits like every other gateway (was the DISPLAY decimals: IQD 2 → 3, MGA 0 → 0,
     * ISK 2 → 2 here), from the stored minor units.
     *
     * PayPal currency precision: at PayPal's OWN decimals — HUF/TWD/JPY whole (before: HUF 1500 sent as "1500.00",
     * which PayPal rejects). Null when the amount has a fraction PayPal cannot carry (HUF 1500.50): never rounded.
     */
    private function price(Transaction $transaction, string $currency): ?string
    {
        return GatewayMinorUnits::gatewayMajorString($this->key(), $transaction->money('amount'), $currency);
    }

    /** price(), or the payer-facing refusal when PayPal cannot carry the amount (checked again here, not only in pay()). */
    private function chargeablePrice(Transaction $transaction, string $currency): string
    {
        $price = $this->price($transaction, $currency);
        if ($price === null) {
            throw new RuntimeException((string) ___('This payment method only charges whole amounts in :currency, and this payment is :amount :currency. Please choose another payment method.', [
                'currency' => $currency, 'amount' => GatewayMinorUnits::majorStringFromMoney($transaction->money('amount'), $currency),
            ]));
        }

        return $price;
    }

    /**
     * Whether a held renewal sale is for the amount the subscription was sold at (custom_id `amount`), exactly —
     * both read as decimals, so PayPal's "1500" and "1500.00" are the same forints. A custom_id without an amount
     * (subscriptions created before it was carried) is not compared.
     *
     * @param  array<string, mixed>  $held
     * @param  array<array-key, mixed>  $metadata
     */
    private function saleAmountMatches(array $held, array $metadata): bool
    {
        $sold = $metadata['amount'] ?? null;
        if (! is_string($sold) || ! is_numeric($sold)) {
            return true;
        }
        $paid = data_get($held, 'amount.total');

        return is_numeric($paid) && Money::decimal((string) $paid)->isEqualTo(Money::decimal($sold));
    }

    private function payMode(Transaction $transaction): string
    {
        $method = $transaction->transaction_method;
        if ($method === 'order') {
            return 'one_time';
        }
        if ($method === 'addon') {
            return $transaction->frequency === 'MONTHLY' ? 'recurring' : 'one_time';
        }
        if ($transaction->frequency === 'LIFETIME') {
            return 'one_time';
        }
        // A charge whose amount changes every period (per-branch plan quantity) is paid one period at a time.
        if (data_get($transaction->details, 'one_time_only') === true) {
            return 'one_time';
        }

        $setting = (string) (config('settings.paypal_payment_mode') ?: 'one_time');
        $mode = $setting === 'both' ? (string) request()->input('payment_mode', 'one_time') : $setting;

        return $mode === 'recurring' ? 'recurring' : 'one_time';
    }

    private function brandName(Transaction $transaction): string
    {
        $store = Settlement::store($transaction);

        return (string) ($store->title ?? config('settings.site_title'));
    }

    /** @param array{client_id:string, secret:string, sandbox:bool} $credentials */
    private function startOrder(Transaction $transaction, array $credentials): ?string
    {
        $currency = $this->currency($transaction);
        $price = $this->chargeablePrice($transaction, $currency);

        $order = $this->client($credentials)->post('/v2/checkout/orders', [
            'intent' => self::holdsCard($transaction) ? 'AUTHORIZE' : 'CAPTURE', // P3-09: a card hold of a weighed basket
            'purchase_units' => [[
                'reference_id' => (string) $transaction->id,
                'custom_id' => (string) $transaction->id,
                'description' => mb_substr(Settlement::title($transaction), 0, 127),
                'amount' => ['currency_code' => $currency, 'value' => $price],
            ]],
            'application_context' => [
                'brand_name' => mb_substr($this->brandName($transaction), 0, 127),
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'return_url' => route('ipn', 'paypal').'?pay_mode=one_time',
                'cancel_url' => Settlement::cancelUrl($transaction),
            ],
        ])->json();

        $approve = $this->link((array) $order, ['approve', 'payer-action']);
        if (empty($order['id']) || $approve === null) {
            Log::info('[paypal] order not created: '.json_encode($order));
            $reason = $order['message'] ?? $order['error']['message'] ?? null;
            app_alert_error($reason ? ___('Payment failed').' : '.$reason : ___('Payment failed, check the credentials.'));

            return null;
        }

        $transaction->update(['payment_id' => $order['id']]);

        return $approve;
    }

    /**
     * Recurring: product (created once) → billing plan → subscription. The pending
     * transaction is removed; each completed sale creates its own through the webhook.
     *
     * @param  array{client_id:string, secret:string, sandbox:bool}  $credentials
     */
    private function startSubscription(Transaction $transaction, array $credentials): string
    {
        $currency = $this->currency($transaction);
        $price = $this->chargeablePrice($transaction, $currency);
        $client = $this->client($credentials);
        $key = $transaction->transaction_method === 'addon'
            ? 'addon_'.($transaction->details->type ?? 'x')
            : (string) $transaction->product_id;
        $productId = 'product_'.$key;

        $product = $client->get('/v1/catalogs/products/'.rawurlencode($productId));
        if ($product->status() === 404) {
            $client->post('/v1/catalogs/products', [
                'id' => $productId,
                'name' => (string) $transaction->product_name,
                'description' => (string) $transaction->product_name,
                'type' => 'SERVICE',
            ])->throw();
        } else {
            $product->throw();
        }

        $plan = $client->post('/v1/billing/plans', [
            'product_id' => $productId,
            'name' => 'plan_'.$key.'_'.$transaction->frequency.'_'.$price.'_'.$currency,
            'status' => 'ACTIVE',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'DAY', 'interval_count' => $transaction->frequency === 'MONTHLY' ? 30 : 365],
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0,
                'pricing_scheme' => ['fixed_price' => ['value' => $price, 'currency_code' => $currency]],
            ]],
            'payment_preferences' => ['auto_bill_outstanding' => true, 'payment_failure_threshold' => 0],
        ])->throw()->json();

        $subscription = $client->post('/v1/billing/subscriptions', [
            'plan_id' => $plan['id'],
            'custom_id' => http_build_query($this->metadata($transaction)),
            'application_context' => [
                'brand_name' => mb_substr((string) config('settings.site_title'), 0, 127),
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'SUBSCRIBE_NOW',
                'payment_method' => ['payer_selected' => 'PAYPAL', 'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED'],
                'return_url' => route('ipn', 'paypal').'?pay_mode=recurring',
                'cancel_url' => AccountPages::url(),
            ],
        ])->throw()->json();

        $approve = $this->link((array) $subscription, ['approve']);
        if ($approve === null) {
            throw new RuntimeException(___('Payment failed, check the credentials.'));
        }

        $transaction->delete();

        return $approve;
    }

    /** @return array<string, string> */
    private function metadata(Transaction $transaction): array
    {
        $metadata = [];
        foreach ($transaction->legacyAttributes() as $name => $value) {
            if ($value !== null && $value !== '' && is_scalar($value)) {
                $metadata[$name] = (string) $value;
            }
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  list<string>  $relations
     */
    private function link(array $resource, array $relations): ?string
    {
        foreach ($resource['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? null, $relations, true) && ! empty($link['href'])) {
                return (string) $link['href'];
            }
        }

        return null;
    }

    /**
     * The capture settles when COMPLETED for our amount and currency.
     *
     * @param  array<string, mixed>  $capture
     */
    private function captureSettles(array $capture, Transaction $transaction): bool
    {
        if (($capture['status'] ?? null) !== 'COMPLETED') {
            return false;
        }

        // The order can be COMPLETED while its capture is still PENDING (held for
        // review, eCheck): only a completed capture of our amount settles.
        $first = $capture['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (is_array($first) && isset($first['status']) && $first['status'] !== 'COMPLETED') {
            Log::warning("[paypal] capture {$first['status']} on transaction {$transaction->id}");

            return false;
        }

        $captured = is_array($first) ? ($first['amount'] ?? null) : null;
        $currency = $this->currency($transaction);
        // PayPal currency precision: the same PayPal-decimals price we asked for (null → nothing PayPal can capture).
        $price = $this->price($transaction, $currency);
        if ($price === null
            || ! is_array($captured)
            || strtoupper((string) ($captured['currency_code'] ?? '')) !== $currency
            // Bug batch 10 · 2: the exact amount we asked for (was ± 0.01 — a KWD capture 0.009 short settled).
            || ! is_numeric($captured['value'] ?? null)
            || ! Money::decimal((string) $captured['value'])->isEqualTo(Money::decimal($price))) {
            Log::warning("[paypal] amount/currency mismatch on transaction {$transaction->id}");

            return false;
        }

        return true;
    }

    /**
     * PayPal's own verification of a notification (POST
     * /v1/notifications/verify-webhook-signature) with the transmission headers.
     *
     * @param  array<string, mixed>  $event
     * @param  array{client_id:string, secret:string, sandbox:bool}  $credentials
     */
    private function signatureIsValid(Request $request, array $event, string $webhookId, array $credentials): bool
    {
        $headers = [
            'auth_algo' => 'PAYPAL-AUTH-ALGO',
            'cert_url' => 'PAYPAL-CERT-URL',
            'transmission_id' => 'PAYPAL-TRANSMISSION-ID',
            'transmission_sig' => 'PAYPAL-TRANSMISSION-SIG',
            'transmission_time' => 'PAYPAL-TRANSMISSION-TIME',
        ];
        $body = ['webhook_id' => $webhookId, 'webhook_event' => $event];
        foreach ($headers as $field => $header) {
            $value = (string) $request->header($header, '');
            if ($value === '') {
                return false;
            }
            $body[$field] = $value;
        }

        try {
            return $this->client($credentials)->post('/v1/notifications/verify-webhook-signature', $body)
                ->json('verification_status') === 'SUCCESS';
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function answer(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $status], $status);
    }

    private function errorMessage(\Throwable $e): string
    {
        if ($e instanceof RequestException) {
            return (string) ($e->response->json('message') ?? $e->response->json('error_description') ?? $e->getMessage());
        }

        return $e->getMessage();
    }
}
