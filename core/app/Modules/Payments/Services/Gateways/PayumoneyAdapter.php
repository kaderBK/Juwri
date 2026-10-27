<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\AccountPages;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * PayU GPO REST API v2.1 (https://developers.payu.com/europe/api/) — the
 * "payumoney" gateway key is historical; the settings are PayU's POS id,
 * second key (MD5) and OAuth client id/secret.
 *
 * initiate: create an order (`extOrderId` = our transaction id, amount in the
 * currency's subunit), keep PayU's `orderId` as the transaction's payment_id
 * and send the buyer to `redirectUri`. PayU calls `webhook/payumoney` on every
 * status change with header OpenPayu-Signature (`signature` = hash(algorithm,
 * raw body + second key)); only a signed COMPLETED notification for our
 * orderId, amount and currency, confirmed by reading the order back, settles.
 * The buyer's return (`ipn/payumoney`) reads the order too: COMPLETED settles,
 * anything else shows "processing" and leaves it to the notification.
 *
 * Keys: store orders use the store's own POS (BYOK), everything else the
 * platform's. Auth: OAuth client_credentials when a client id/secret is set,
 * otherwise HTTP Basic with POS id and second key.
 */
class PayumoneyAdapter implements GatewayAdapter
{
    public const LIVE = 'https://secure.payu.com';

    public const SANDBOX = 'https://secure.snd.payu.com';

    private const ALGORITHMS = ['md5' => 'md5', 'sha1' => 'sha1', 'sha256' => 'sha256', 'sha384' => 'sha384', 'sha512' => 'sha512'];

    public function key(): string
    {
        return 'payumoney';
    }

    public function initiate(Transaction $transaction): Response
    {
        try {
            $creds = $this->credentials($transaction);
            if ($creds['pos_id'] === '' && $creds['client_id'] === '') {
                throw new RuntimeException(___('This payment method is not configured.'));
            }

            $currency = Settlement::currency($transaction, 'PLN');
            $amount = (string) GatewayMinorUnits::fromMoney($transaction->money('amount'), $currency);
            $title = Settlement::title($transaction);
            $query = '?order_id='.$transaction->id;

            $body = array_filter([
                'notifyUrl' => url('webhook/payumoney').$query,
                'continueUrl' => route('ipn', 'payumoney').$query,
                'customerIp' => (string) request()->ip(),
                'merchantPosId' => $creds['pos_id'] !== '' ? $creds['pos_id'] : $creds['client_id'],
                'description' => $title,
                'currencyCode' => $currency,
                'totalAmount' => $amount,
                'extOrderId' => (string) $transaction->id,
                'products' => [['name' => $title, 'unitPrice' => $amount, 'quantity' => '1']],
                'buyer' => $this->buyer($transaction),
            ], fn ($value) => $value !== null && $value !== []);

            $response = $this->client($creds)->post('/api/v2_1/orders', $body);
            $order = (array) $response->json();
            if (($order['status']['statusCode'] ?? null) !== 'SUCCESS' || empty($order['orderId']) || empty($order['redirectUri'])) {
                $message = (string) ($order['status']['statusDesc'] ?? $order['status']['statusCode'] ?? ___('Payment failed, please try again.'));
                Log::info('PayU order refused: '.$message);
                app_alert_error($message);

                return back()->withInput();
            }

            $transaction->update(['payment_id' => (string) $order['orderId']]);

            return redirect()->away((string) $order['redirectUri']);
        } catch (\Throwable $e) {
            PaymentLog::failure('payumoney', $e, ['transaction_id' => $transaction->id]);
            app_alert_error($e->getMessage());

            return back()->withInput();
        }
    }

    public function handleReturn(Request $request): Response
    {
        $id = (string) $request->input('order_id', '');
        $transaction = ctype_digit($id) ? Transaction::find((int) $id) : null;
        if ($transaction === null) {
            if ($id === '') {
                app_alert_success(___('Your payment is processing.'));

                return redirect()->to(AccountPages::url());
            }
            app_alert_error(___('Invalid transaction, please try again.'));

            return back();
        }

        if ($transaction->status === null && (string) $transaction->payment_id !== '') {
            try {
                if ($this->confirmed($this->credentials($transaction), $transaction, (string) $transaction->payment_id)) {
                    // J25-7: credit durable via the outbox, same answer to the buyer.
                    return Settlement::settleForBuyer($transaction, 'payumoney', null);
                }
            } catch (\Throwable $e) {
                PaymentLog::settlementFailed('payumoney', $e);
            }
        }

        return Settlement::processingForBuyer($transaction);
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $order = json_decode($payload, true)['order'] ?? null;
        if (! is_array($order)) {
            PaymentLog::webhookRejected('payumoney', 'invalid payload');

            return $this->answer(400, 'Invalid payload.');
        }

        $reference = (string) ($order['extOrderId'] ?? $request->input('order_id', ''));
        $known = ctype_digit($reference) ? Transaction::find((int) $reference) : null;
        $creds = $known !== null ? $this->credentials($known) : $this->platformCredentials();
        if (! $this->signatureValid($payload, (string) ($request->header('OpenPayu-Signature') ?? $request->header('X-OpenPayU-Signature', '')), $creds['second_key'])) {
            PaymentLog::webhookRejected('payumoney', 'invalid signature');

            return $this->answer(400, 'Invalid PayU signature.');
        }

        $orderId = (string) ($order['orderId'] ?? '');
        $transaction = $known !== null && $known->status === null && $orderId !== '' && (string) $known->payment_id === $orderId ? $known : null;
        if ($transaction === null || ($order['status'] ?? null) !== 'COMPLETED') {
            return $this->answer(200, 'successful');
        }

        try {
            if (! $this->orderMatches($order, $transaction) || ! $this->confirmed($creds, $transaction, $orderId)) {
                return $this->answer(200, 'not confirmed');
            }
            // J25-7: the verified event is "payumoney confirmed this payment paid" (checked with the provider above), so a repeated or concurrent confirmation is a no-op (WebhookReceipts) — a copy that arrives before the provider confirms is never dropped.
            if (! WebhookReceipts::once('payumoney', WebhookReceipts::key($transaction->id, 'paid'), function () use ($transaction) {
                Settlement::settle($transaction, 'payumoney'); // J25-7: credit durable via the outbox

                return true;
            }, false)) {
                return $this->answer(200, 'duplicate');
            }
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('payumoney', $e);

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
        $own = Settlement::currency($transaction, 'PLN');

        return RefundGuard::run($transaction, $amount, $currency, $this->key(), GatewayMinorUnits::decimals($own), function (Money $money) use ($transaction) {
            try {
                $response = $this->client($this->credentials($transaction))
                    ->post('/api/v2_1/orders/'.rawurlencode((string) $transaction->payment_id).'/refunds', ['refund' => [
                        'description' => 'Refund '.$transaction->id,
                        'amount' => (string) GatewayMinorUnits::fromMoney($money),
                    ]]);
                $body = (array) $response->json();
                if (($body['status']['statusCode'] ?? null) !== 'SUCCESS') {
                    return ['ok' => false, 'reference' => null, 'message' => (string) ($body['status']['statusDesc'] ?? $body['status']['statusCode'] ?? 'Refund refused')];
                }

                return ['ok' => true, 'reference' => isset($body['refund']['refundId']) ? (string) $body['refund']['refundId'] : null, 'message' => null];
            } catch (ConnectionException $e) {
                throw $e; // no answer: outcome unknown — RefundGuard keeps the reservation (bug batch 6)
            } catch (\Throwable $e) {
                return ['ok' => false, 'reference' => null, 'message' => $e->getMessage()];
            }
        }, tenFils: true);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * `sender=…;signature=…;algorithm=…;content=DOCUMENT` — signature is the hex
     * digest of the raw body followed by the second key.
     */
    private function signatureValid(string $payload, string $header, string $secondKey): bool
    {
        if ($secondKey === '' || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(';', $header) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[strtolower(trim($name))] = trim($value);
        }
        $algorithm = self::ALGORITHMS[str_replace('-', '', strtolower($parts['algorithm'] ?? 'md5'))] ?? null;
        $signature = strtolower($parts['signature'] ?? '');

        return $algorithm !== null && $signature !== '' && hash_equals(hash($algorithm, $payload.$secondKey), $signature);
    }

    /**
     * Read the order back from PayU: COMPLETED, ours, for our amount.
     *
     * @param  array{pos_id:string,second_key:string,client_id:string,client_secret:string,base:string}  $creds
     */
    private function confirmed(array $creds, Transaction $transaction, string $orderId): bool
    {
        $order = $this->client($creds)->get('/api/v2_1/orders/'.rawurlencode($orderId))->throw()->json('orders.0');

        return is_array($order) && ($order['status'] ?? null) === 'COMPLETED'
            && (string) ($order['orderId'] ?? '') === $orderId && $this->orderMatches($order, $transaction);
    }

    /** @param  array<string, mixed>  $order */
    private function orderMatches(array $order, Transaction $transaction): bool
    {
        if ((string) ($order['extOrderId'] ?? $transaction->id) !== (string) $transaction->id) {
            return false;
        }
        $currency = strtoupper((string) ($order['currencyCode'] ?? ''));
        $paid = (int) ($order['totalAmount'] ?? 0) / (10 ** GatewayMinorUnits::decimals($currency));

        return Settlement::amountMatches($transaction, $paid, $currency, 'payumoney', Settlement::currency($transaction, 'PLN'));
    }

    /** @return array<string, string>|null */
    private function buyer(Transaction $transaction): ?array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $owner = Store::find($transaction->store_id)?->user;

            return array_filter([
                'email' => (string) ($transaction->details->customer_email ?? $owner->email ?? ''),
                'firstName' => (string) ($transaction->details->customer_name ?? ''),
            ]) ?: null;
        }
        $user = request()->user();

        return $user === null ? null : array_filter(['email' => (string) $user->email, 'firstName' => (string) $user->name]);
    }

    /** @param  array{pos_id:string,second_key:string,client_id:string,client_secret:string,base:string}  $creds */
    private function client(array $creds): PendingRequest
    {
        // PayU answers a created order with 302 + JSON; the redirect is for the buyer, not for us.
        $client = Http::baseUrl($creds['base'])->acceptJson()->asJson()->timeout(30)->withOptions(['allow_redirects' => false]);

        return $creds['client_id'] !== '' && $creds['client_secret'] !== ''
            ? $client->withToken($this->accessToken($creds))
            : $client->withBasicAuth($creds['pos_id'], $creds['second_key']);
    }

    /** @param  array{pos_id:string,second_key:string,client_id:string,client_secret:string,base:string}  $creds */
    private function accessToken(array $creds): string
    {
        $cacheKey = 'payu-oauth:'.hash('sha256', $creds['base'].'|'.$creds['client_id'].'|'.$creds['client_secret']);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = Http::asForm()->acceptJson()->timeout(30)->post($creds['base'].'/pl/standard/user/oauth/authorize', [
            'grant_type' => 'client_credentials',
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
        ])->throw()->json();
        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') {
            throw new RuntimeException('PayU did not return an access token.');
        }
        Cache::put($cacheKey, $access, max(60, (int) ($token['expires_in'] ?? 3600) - 60));

        return $access;
    }

    /** @return array{pos_id:string,second_key:string,client_id:string,client_secret:string,base:string} */
    private function credentials(Transaction $transaction): array
    {
        if (! Settlement::usesStoreGateway($transaction)) {
            return $this->platformCredentials();
        }
        $options = store_options($transaction->store_id);

        return $this->creds(
            $options->restaurant_payumoney_merchant_pos_id ?? '',
            $options->restaurant_payumoney_signature_key ?? '',
            $options->restaurant_payumoney_oauth_client_id ?? '',
            $options->restaurant_payumoney_oauth_client_secret ?? '',
            $options->restaurant_payumoney_sandbox_mode ?? '',
        );
    }

    /** @return array{pos_id:string,second_key:string,client_id:string,client_secret:string,base:string} */
    private function platformCredentials(): array
    {
        return $this->creds(
            config('settings.payumoney_merchant_pos_id'),
            config('settings.payumoney_signature_key'),
            config('settings.payumoney_oauth_client_id'),
            config('settings.payumoney_oauth_client_secret'),
            config('settings.payumoney_sandbox_mode'),
        );
    }

    /** @return array{pos_id:string,second_key:string,client_id:string,client_secret:string,base:string} */
    private function creds(mixed $posId, mixed $secondKey, mixed $clientId, mixed $clientSecret, mixed $mode): array
    {
        $sandbox = Settlement::isSandbox($mode);

        return GatewaySandbox::gate('payumoney', $sandbox, [
            'pos_id' => trim((string) $posId),
            'second_key' => trim((string) $secondKey),
            'client_id' => trim((string) $clientId),
            'client_secret' => trim((string) $clientSecret),
            'base' => $sandbox ? self::SANDBOX : self::LIVE,
        ]);
    }

    private function answer(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $status], $status);
    }
}
