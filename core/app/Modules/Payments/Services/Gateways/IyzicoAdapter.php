<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * iyzico Checkout Form (https://docs.iyzico.com/en/payment-methods/checkoutform,
 * auth: https://docs.iyzico.com/en/getting-started/preliminaries/authentication/hmacsha256-auth,
 * signatures: https://docs.iyzico.com/en/advanced/response-signature-validation,
 * webhook: https://docs.iyzico.com/en/advanced/webhook).
 *
 * initiate: initialize a hosted checkout form (conversationId = basketId = our
 * transaction id) and send the buyer to its paymentPageUrl. iyzico posts a
 * `token` back to `ipn/iyzico?order_id=<tx>`; the return retrieves the form
 * result (authenticated call), checks the response signature, and settles only
 * paymentStatus SUCCESS, fraud check passed, for our basket, price and
 * currency. `webhook/iyzico` (X-IYZ-SIGNATURE-V3) settles a payment whose buyer
 * never came back, after the same retrieve.
 *
 * Keys: store orders use the store's API/secret key (BYOK), everything else the
 * platform's.
 */
class IyzicoAdapter implements GatewayAdapter
{
    public const LIVE = 'https://api.iyzipay.com';

    public const SANDBOX = 'https://sandbox-api.iyzipay.com';

    public function key(): string
    {
        return 'iyzico';
    }

    public function initiate(Transaction $transaction): Response
    {
        try {
            $creds = $this->credentials($transaction);
            if ($creds['api_key'] === '' || $creds['secret'] === '') {
                throw new RuntimeException(___('This payment method is not configured.'));
            }

            $price = $this->price($transaction->amount, Settlement::currency($transaction, 'TRY'));
            $title = Settlement::title($transaction);
            $buyer = $this->buyer($transaction);
            $address = [
                'contactName' => trim($buyer['name'].' '.$buyer['surname']),
                'city' => $buyer['city'],
                'country' => $buyer['country'],
                'address' => $buyer['registrationAddress'],
                'zipCode' => $buyer['zipCode'],
            ];

            $result = $this->call($creds, '/payment/iyzipos/checkoutform/initialize/auth/ecom', [
                'locale' => $this->locale(),
                'conversationId' => (string) $transaction->id,
                'price' => $price,
                'paidPrice' => $price,
                'currency' => Settlement::currency($transaction, 'TRY'),
                'basketId' => (string) $transaction->id,
                'paymentGroup' => 'PRODUCT',
                'callbackUrl' => route('ipn', 'iyzico').'?order_id='.$transaction->id,
                'buyer' => $buyer,
                'shippingAddress' => $address,
                'billingAddress' => $address,
                'basketItems' => [[
                    'id' => (string) $transaction->id,
                    'name' => $title,
                    'category1' => 'Collectibles',
                    'itemType' => 'VIRTUAL',
                    'price' => $price,
                ]],
            ]);

            if (($result['status'] ?? null) !== 'success' || empty($result['paymentPageUrl'])) {
                $message = (string) ($result['errorMessage'] ?? ___('Payment failed, please try again.'));
                Log::info('Iyzico checkout form refused: '.$message);
                app_alert_error($message);

                return back()->withInput();
            }

            return redirect()->away((string) $result['paymentPageUrl']);
        } catch (\Throwable $e) {
            PaymentLog::failure('iyzico', $e, ['transaction_id' => $transaction->id]);
            app_alert_error($e->getMessage());

            return back()->withInput();
        }
    }

    public function handleReturn(Request $request): Response
    {
        $token = (string) $request->input('token', '');
        if ($token === '') {
            return Settlement::failed();
        }
        $id = (string) $request->input('order_id', '');
        $known = ctype_digit($id) ? Transaction::find((int) $id) : null;
        if ($id !== '' && $known === null) {
            app_alert_error(___('Invalid transaction, please try again.'));

            return back();
        }

        try {
            $creds = $known !== null ? $this->credentials($known) : $this->platformCredentials();
            $form = $this->retrieve($creds, $token);
            $transaction = $this->settleable($form, $creds);
            // The form was read with the keys of the `order_id` transaction (or
            // the platform's). It may only settle that same transaction, and only
            // when those are the keys that transaction is paid with: otherwise a
            // store owner could pay another pending transaction into their own
            // iyzico account and have it settled here.
            if ($transaction !== null
                && (($known !== null && $transaction->id !== $known->id) || $this->credentials($transaction) !== $creds)) {
                PaymentLog::webhookRejected('iyzico', 'return names another transaction');

                return Settlement::invalid();
            }
            if ($transaction === null) {
                if (($form['status'] ?? null) === 'success' && ($form['paymentStatus'] ?? null) === 'SUCCESS'
                    && ! Settlement::pending(['id' => (string) ($form['basketId'] ?? '')], withoutPaymentId: true)) {
                    return Settlement::invalid();
                }

                return Settlement::failed((string) ($form['errorMessage'] ?? ''));
            }

            // J25-7: credit durable via the outbox, same answer to the buyer.
            return Settlement::settleForBuyer($transaction, 'iyzico', (string) $form['paymentId']);
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('iyzico', $e);

            return Settlement::failed();
        }
    }

    public function handleWebhook(Request $request): Response
    {
        $event = json_decode($request->getContent(), true);
        if (! is_array($event)) {
            PaymentLog::webhookRejected('iyzico', 'invalid payload');

            return $this->answer(400, 'Invalid payload.');
        }

        $reference = (string) ($event['paymentConversationId'] ?? '');
        $known = ctype_digit($reference) ? Transaction::find((int) $reference) : null;
        $creds = $known !== null ? $this->credentials($known) : $this->platformCredentials();
        if (! $this->webhookSignatureValid($event, (string) $request->header('X-IYZ-SIGNATURE-V3', ''), $creds['secret'])) {
            PaymentLog::webhookRejected('iyzico', 'invalid signature');

            return $this->answer(400, 'Invalid iyzico signature.');
        }

        $token = (string) ($event['token'] ?? '');
        if ($known === null || $known->status !== null || $token === '' || ($event['status'] ?? null) !== 'SUCCESS') {
            return $this->answer(200, 'successful');
        }

        try {
            $form = $this->retrieve($creds, $token);
            $transaction = $this->settleable($form, $creds);
            if ($transaction === null || $transaction->id !== $known->id) {
                return $this->answer(200, 'not confirmed');
            }
            // J25-7: the verified event is "iyzico confirmed this payment paid" (checked with the provider above), so a repeated or concurrent confirmation is a no-op (WebhookReceipts) — a copy that arrives before the provider confirms is never dropped.
            if (! WebhookReceipts::once('iyzico', WebhookReceipts::key($transaction->id, 'paid'), function () use ($form, $transaction) {
                Settlement::settle($transaction, 'iyzico', (string) $form['paymentId']); // J25-7: credit durable via the outbox

                return true;
            }, false)) {
                return $this->answer(200, 'duplicate');
            }
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('iyzico', $e);

            return $this->answer(500, 'settlement failed');
        }

        return $this->answer(200, 'successful');
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        return RefundGuard::run($transaction, $amount, $currency, $this->key(), 2, function (Money $money) use ($transaction) {
            try {
                $result = $this->call($this->credentials($transaction), '/v2/payment/refund', [
                    'locale' => $this->locale(),
                    'conversationId' => (string) $transaction->id,
                    'paymentId' => (string) $transaction->payment_id,
                    'price' => GatewayMinorUnits::majorStringFromMoney($money),
                    'currency' => $money->currency(),
                    'ip' => (string) request()->ip(),
                ]);
                if (($result['status'] ?? null) !== 'success') {
                    return ['ok' => false, 'reference' => null, 'message' => (string) ($result['errorMessage'] ?? 'Refund refused')];
                }

                return ['ok' => true, 'reference' => (string) ($result['paymentTransactionId'] ?? $result['paymentId'] ?? $transaction->payment_id), 'message' => null];
            } catch (ConnectionException $e) {
                throw $e; // no answer: outcome unknown — RefundGuard keeps the reservation (bug batch 6)
            } catch (\Throwable $e) {
                return ['ok' => false, 'reference' => null, 'message' => $e->getMessage()];
            }
        });
    }

    /* ------------------------------------------------------------ internals */

    /**
     * @param  array{api_key:string,secret:string,base:string}  $creds
     * @return array<string, mixed>
     */
    private function retrieve(array $creds, string $token): array
    {
        return $this->call($creds, '/payment/iyzipos/checkoutform/auth/ecom/detail', [
            'locale' => $this->locale(),
            'token' => $token,
        ]);
    }

    /**
     * The pending transaction a retrieved checkout form pays in full, or null.
     *
     * @param  array<string, mixed>  $form
     * @param  array{api_key:string,secret:string,base:string}  $creds
     */
    private function settleable(array $form, array $creds): ?Transaction
    {
        if (($form['status'] ?? null) !== 'success' || ($form['paymentStatus'] ?? null) !== 'SUCCESS' || empty($form['paymentId'])) {
            Log::info('Iyzico Payment failed: '.($form['errorMessage'] ?? ($form['paymentStatus'] ?? 'unknown')));

            return null;
        }
        if (isset($form['signature']) && ! $this->responseSignatureValid($form, $creds['secret'])) {
            PaymentLog::webhookRejected('iyzico', 'invalid response signature');

            return null;
        }
        if (isset($form['fraudStatus']) && (int) $form['fraudStatus'] !== 1) {
            Log::warning('Iyzico payment '.$form['paymentId'].' held by the fraud check (fraudStatus '.$form['fraudStatus'].')');

            return null;
        }

        $transaction = Settlement::pending(['id' => (string) ($form['basketId'] ?? '')], withoutPaymentId: true);
        if ($transaction === null || (string) ($form['conversationId'] ?? $transaction->id) !== (string) $transaction->id) {
            return null;
        }
        if ((float) ($form['paidPrice'] ?? 0) + 0.001 < (float) ($form['price'] ?? 0)
            || ! Settlement::amountMatches($transaction, (float) ($form['price'] ?? 0), (string) ($form['currency'] ?? ''), 'iyzico', Settlement::currency($transaction, 'TRY'))) {
            return null;
        }

        return $transaction;
    }

    /**
     * Checkout form retrieve: hex HMAC-SHA256 of paymentStatus, paymentId,
     * currency, basketId, conversationId, paidPrice, price, token joined by ":"
     * (prices without trailing zeros).
     *
     * @param  array<string, mixed>  $form
     */
    private function responseSignatureValid(array $form, string $secret): bool
    {
        $text = implode(':', [
            $form['paymentStatus'] ?? '', $form['paymentId'] ?? '', $form['currency'] ?? '', $form['basketId'] ?? '',
            $form['conversationId'] ?? '', $this->plainNumber($form['paidPrice'] ?? ''), $this->plainNumber($form['price'] ?? ''),
            $form['token'] ?? '',
        ]);

        return $secret !== '' && hash_equals(hash_hmac('sha256', $text, $secret), strtolower((string) $form['signature']));
    }

    /**
     * X-IYZ-SIGNATURE-V3: hex HMAC-SHA256 (key = secret key) of secret key +
     * iyziEventType + iyziPaymentId + token + paymentConversationId + status
     * (checkout form events; without token for direct API events).
     *
     * @param  array<string, mixed>  $event
     */
    private function webhookSignatureValid(array $event, string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }
        $text = $secret.($event['iyziEventType'] ?? '')
            .($event['iyziPaymentId'] ?? $event['paymentId'] ?? '')
            .(isset($event['token']) ? $event['token'] : '')
            .($event['paymentConversationId'] ?? '').($event['status'] ?? '');

        return hash_equals(hash_hmac('sha256', $text, $secret), strtolower($signature));
    }

    /**
     * An authenticated (IYZWSv2) JSON call.
     *
     * @param  array{api_key:string,secret:string,base:string}  $creds
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(array $creds, string $path, array $body): array
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $random = now()->getTimestampMs().Str::random(8);
        $signature = hash_hmac('sha256', $random.$path.$json, $creds['secret']);
        $authorization = base64_encode('apiKey:'.$creds['api_key'].'&randomKey:'.$random.'&signature:'.$signature);

        return (array) Http::withHeaders(['Authorization' => 'IYZWSv2 '.$authorization, 'x-iyzi-rnd' => $random])
            ->withBody($json, 'application/json')->acceptJson()->timeout(30)
            ->post($creds['base'].$path)->json();
    }

    /** @return array<string, string> */
    private function buyer(Transaction $transaction): array
    {
        $geo = user_ip_lookup()['location'];
        $known = fn ($value) => ! in_array((string) $value, ['', 'Other', 'Unknown'], true) ? (string) $value : '';

        if (Settlement::usesStoreGateway($transaction)) {
            $store = Store::find($transaction->store_id);
            $name = (string) ($transaction->details->customer_name ?? '');
            $email = (string) ($transaction->details->customer_email ?? $store?->user->email ?? '');
            $id = (string) $transaction->store_id;
            $address = (string) ($store->address ?? '');
            $city = $known($geo['city'] ?? '');
            $country = $known($geo['country'] ?? '');
            $zip = $known($geo['postal_code'] ?? '');
        } else {
            $user = request()->user();
            $options = $user !== null ? user_options($user->id) : null;
            $name = (string) ($options->billing_name ?? $user->name ?? '');
            $email = (string) ($user->email ?? request()->input('email', ''));
            $id = (string) ($user->id ?? $transaction->id);
            $address = (string) ($options->billing_address ?? '');
            $city = (string) ($options->billing_city ?? $known($geo['city'] ?? ''));
            $country = (string) ($options->billing_country ?? $known($geo['country'] ?? ''));
            $zip = (string) ($options->billing_zipcode ?? '');
        }

        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        $first = $parts[0] ?? '';
        $first = $first !== '' ? $first : 'Customer';

        return [
            'id' => $id,
            'name' => $first,
            'surname' => $parts[1] ?? $first,
            'email' => $email !== '' ? $email : (string) config('mail.from.address'),
            'identityNumber' => str_pad(preg_replace('/\D/', '', $id) ?: '0', 11, '0', STR_PAD_LEFT),
            'registrationAddress' => $address !== '' ? $address : 'N/A',
            'ip' => (string) request()->ip(),
            'city' => $city !== '' ? $city : 'N/A',
            'country' => $country !== '' ? $country : 'N/A',
            'zipCode' => $zip,
        ];
    }

    private function locale(): string
    {
        return strtolower((string) get_lang()) === 'tr' ? 'tr' : 'en';
    }

    /** iyzico `price`/`paidPrice`: a major-unit decimal string at the currency's own decimals (all its currencies are 2). */
    private function price(mixed $amount, string $currency): string
    {
        return GatewayMinorUnits::toMajorString($amount, $currency);
    }

    private function plainNumber(mixed $value): string
    {
        if (! is_numeric($value)) {
            return (string) $value;
        }
        $text = number_format((float) $value, 8, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }

    /** @return array{api_key:string,secret:string,base:string} */
    private function credentials(Transaction $transaction): array
    {
        if (! Settlement::usesStoreGateway($transaction)) {
            return $this->platformCredentials();
        }
        $options = store_options($transaction->store_id);

        return $this->creds($options->restaurant_iyzico_api_key ?? '', $options->restaurant_iyzico_secret_key ?? '', $options->restaurant_iyzico_sandbox_mode ?? '');
    }

    /** @return array{api_key:string,secret:string,base:string} */
    private function platformCredentials(): array
    {
        return $this->creds(config('settings.iyzico_api_key'), config('settings.iyzico_secret_key'), config('settings.iyzico_sandbox_mode'));
    }

    /** @return array{api_key:string,secret:string,base:string} */
    private function creds(mixed $apiKey, mixed $secret, mixed $mode): array
    {
        $sandbox = Settlement::isSandbox($mode);

        return GatewaySandbox::gate('iyzico', $sandbox, [
            'api_key' => trim((string) $apiKey),
            'secret' => trim((string) $secret),
            'base' => $sandbox ? self::SANDBOX : self::LIVE,
        ]);
    }

    private function answer(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $status], $status);
    }
}
