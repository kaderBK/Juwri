<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * PayTabs Hosted Payment Page, PT2 API
 * (https://docs.paytabs.com/manuals/PT-API-Endpoints/,
 * signature rules: https://support.paytabs.com/en/support/solutions/articles/60000718961).
 *
 * initiate: payment/request on the profile's regional endpoint (cart_id = our
 * transaction id), keep `tran_ref` as the transaction's payment_id, send the
 * buyer to `redirect_url`. The buyer's browser comes back to `ipn/paytabs` as a
 * form post whose `signature` is hex HMAC-SHA256 (server key) of the other
 * non-empty fields sorted by name and URL-encoded; PayTabs' server callback
 * goes to `webhook/paytabs` with header `Signature` = HMAC-SHA256 of the raw
 * JSON body. Both are then confirmed with payment/query (status `A`, same
 * cart, amount and currency) before settling, once.
 *
 * Keys: store orders use the store's profile/server key/region (BYOK),
 * everything else the platform's.
 */
class PaytabsAdapter implements GatewayAdapter
{
    /** Regional API hosts; the key is the region saved in the settings. */
    public const REGIONS = [
        'ARE' => 'https://secure.paytabs.com/',
        'SAU' => 'https://secure.paytabs.sa/',
        'OMN' => 'https://secure-oman.paytabs.com/',
        'JOR' => 'https://secure-jordan.paytabs.com/',
        'EGY' => 'https://secure-egypt.paytabs.com/',
        'IRQ' => 'https://secure-iraq.paytabs.com/',
        'PSE' => 'https://secure-palestine.paytabs.com/',
        'GLOBAL' => 'https://secure-global.paytabs.com/',
    ];

    public function key(): string
    {
        return 'paytabs';
    }

    public function initiate(Transaction $transaction): Response
    {
        try {
            $creds = $this->credentials($transaction);
            $currency = Settlement::currency($transaction, 'AED');

            $result = $this->client($creds)->post('payment/request', [
                'profile_id' => $this->profileId($creds['profile_id']),
                'tran_type' => 'sale',
                'tran_class' => 'ecom',
                'cart_id' => (string) $transaction->id,
                'cart_description' => Settlement::title($transaction),
                'cart_currency' => $currency,
                'cart_amount' => GatewayMinorUnits::majorStringFromMoney($transaction->money('amount'), $currency),
                'callback' => url('webhook/paytabs'),
                'return' => route('ipn', 'paytabs'),
                'hide_shipping' => true,
            ])->json();

            if (empty($result['redirect_url']) || empty($result['tran_ref'])) {
                $message = (string) ($result['message'] ?? ___('Payment failed, please try again.'));
                Log::info('PayTabs payment request refused: '.$message);
                app_alert_error($message);

                return back()->withInput();
            }

            $transaction->update(['payment_id' => (string) $result['tran_ref']]);

            return redirect()->away((string) $result['redirect_url']);
        } catch (\Throwable $e) {
            PaymentLog::failure('paytabs', $e, ['transaction_id' => $transaction->id]);
            app_alert_error($e->getMessage());

            return back()->withInput();
        }
    }

    public function handleReturn(Request $request): Response
    {
        // Payments started before the callback moved to webhook/paytabs still post their JSON here.
        if ($request->isJson()) {
            return $this->handleWebhook($request);
        }

        $fields = $request->post();
        $tranRef = (string) ($fields['tranRef'] ?? '');
        $cartId = (string) ($fields['cartId'] ?? '');
        $transaction = ($tranRef === '' || $cartId === '') ? null : Settlement::pending(['id' => $cartId, 'payment_id' => $tranRef]);
        if ($transaction === null) {
            return Settlement::invalid();
        }

        try {
            $creds = $this->credentials($transaction);
            if (! $this->returnSignatureValid($fields, $creds['server_key'])) {
                Log::warning("PayTabs return with a bad signature for transaction {$transaction->id}");

                return Settlement::failed();
            }
            if (($fields['respStatus'] ?? null) !== 'A') {
                $reason = (string) ($fields['respMessage'] ?? '');
                Log::info('PayTabs Payment failed: '.$reason);

                return Settlement::failed($reason);
            }
            if (! $this->confirmed($creds, $transaction)) {
                return Settlement::failed();
            }

            // J25-7: credit durable via the outbox, same answer to the buyer.
            return Settlement::settleForBuyer($transaction, 'paytabs', null);
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('paytabs', $e);

            return Settlement::failed();
        }
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $event = json_decode($payload, true);
        if (! is_array($event)) {
            PaymentLog::webhookRejected('paytabs', 'invalid payload');

            return $this->answer(400, 'Invalid payload.');
        }

        $cartId = (string) ($event['cart_id'] ?? '');
        $known = ctype_digit($cartId) ? Transaction::find((int) $cartId) : null;
        try {
            $serverKey = $known !== null ? $this->credentials($known)['server_key'] : (string) config('settings.paytabs_secret_key', '');
        } catch (RuntimeException) {
            $serverKey = '';
        }
        $signature = strtolower((string) $request->header('Signature', ''));
        if ($serverKey === '' || $signature === '' || ! hash_equals(hash_hmac('sha256', $payload, $serverKey), $signature)) {
            PaymentLog::webhookRejected('paytabs', 'invalid signature');

            return $this->answer(400, 'Invalid PayTabs signature.');
        }

        $tranRef = (string) ($event['tran_ref'] ?? '');
        $transaction = $known !== null && $known->status === null && $tranRef !== '' && (string) $known->payment_id === $tranRef ? $known : null;
        if ($transaction === null || ($event['payment_result']['response_status'] ?? null) !== 'A') {
            return $this->answer(200, 'successful');
        }

        try {
            if (! $this->confirmed($this->credentials($transaction), $transaction)) {
                return $this->answer(200, 'not confirmed');
            }
            // J25-7: the verified event is "paytabs confirmed this payment paid" (checked with the provider above), so a repeated or concurrent confirmation is a no-op (WebhookReceipts) — a copy that arrives before the provider confirms is never dropped.
            if (! WebhookReceipts::once('paytabs', WebhookReceipts::key($transaction->id, 'paid'), function () use ($transaction) {
                Settlement::settle($transaction, 'paytabs'); // J25-7: credit durable via the outbox

                return true;
            }, false)) {
                return $this->answer(200, 'duplicate');
            }
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('paytabs', $e);

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
        $own = Settlement::currency($transaction, 'AED');

        return RefundGuard::run($transaction, $amount, $currency, $this->key(), GatewayMinorUnits::decimals($own), function (Money $money) use ($transaction) {
            try {
                $creds = $this->credentials($transaction);
                $result = $this->client($creds)->post('payment/request', [
                    'profile_id' => $this->profileId($creds['profile_id']),
                    'tran_type' => 'refund',
                    'tran_class' => 'ecom',
                    'cart_id' => (string) $transaction->id,
                    'cart_currency' => $money->currency(),
                    'cart_amount' => GatewayMinorUnits::majorStringFromMoney($money),
                    'cart_description' => 'Refund '.$transaction->id,
                    'tran_ref' => (string) $transaction->payment_id,
                ])->json();
                if (($result['payment_result']['response_status'] ?? null) !== 'A') {
                    return ['ok' => false, 'reference' => null, 'message' => (string) ($result['payment_result']['response_message'] ?? $result['message'] ?? 'Refund refused')];
                }

                return ['ok' => true, 'reference' => (string) ($result['tran_ref'] ?? ''), 'message' => null];
            } catch (ConnectionException $e) {
                throw $e; // no answer: outcome unknown — RefundGuard keeps the reservation (bug batch 6)
            } catch (\Throwable $e) {
                return ['ok' => false, 'reference' => null, 'message' => $e->getMessage()];
            }
        });
    }

    /* ------------------------------------------------------------ internals */

    /**
     * payment/query: authorised (`A`) for our cart, amount and currency.
     *
     * @param  array{profile_id:string,server_key:string,base:string}  $creds
     */
    private function confirmed(array $creds, Transaction $transaction): bool
    {
        $result = $this->client($creds)->post('payment/query', [
            'profile_id' => $this->profileId($creds['profile_id']),
            'tran_ref' => (string) $transaction->payment_id,
        ])->json();

        if (($result['payment_result']['response_status'] ?? null) !== 'A' || (string) ($result['cart_id'] ?? '') !== (string) $transaction->id) {
            Log::info("PayTabs query for transaction {$transaction->id}: ".($result['payment_result']['response_message'] ?? $result['message'] ?? 'not authorised'));

            return false;
        }

        $currency = Settlement::currency($transaction, 'AED');
        if (! Settlement::amountMatches($transaction, (string) ($result['cart_amount'] ?? ''), (string) ($result['cart_currency'] ?? ''), 'paytabs', $currency)) {
            return false;
        }

        // `cart_amount` is what we asked for; `tran_total` in `tran_currency` is "the actual amount deducted from the
        // customer" (support.paytabs.com … articles/60000709843). In the cart's own currency it must be the same amount
        // too. before: only the cart was compared, so 1.000 OMR deducted settled a 1.234 OMR order. A tran_currency other
        // than ours (PayTabs converted to the profile currency) is not comparable and keeps the cart check alone.
        $tranCurrency = strtoupper(trim((string) ($result['tran_currency'] ?? '')));
        if ($tranCurrency === $currency && isset($result['tran_total'])
            && ! Settlement::amountMatches($transaction, (string) $result['tran_total'], $tranCurrency, 'paytabs', $currency)) {
            return false;
        }

        return true;
    }

    /** @param  array<string, mixed>  $fields */
    private function returnSignatureValid(array $fields, string $serverKey): bool
    {
        $signature = strtolower((string) ($fields['signature'] ?? ''));
        unset($fields['signature']);
        $fields = array_filter($fields, fn ($value) => $value !== null && $value !== '');
        ksort($fields);

        return $serverKey !== '' && $signature !== ''
            && hash_equals(hash_hmac('sha256', http_build_query($fields), $serverKey), $signature);
    }

    private function profileId(string $profileId): int|string
    {
        return ctype_digit($profileId) ? (int) $profileId : $profileId;
    }

    /** @param  array{profile_id:string,server_key:string,base:string}  $creds */
    private function client(array $creds): PendingRequest
    {
        return Http::baseUrl($creds['base'])->withHeaders(['authorization' => $creds['server_key']])
            ->asJson()->acceptJson()->timeout(30);
    }

    /** @return array{profile_id:string,server_key:string,base:string} */
    private function credentials(Transaction $transaction): array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $options = store_options($transaction->store_id);
            [$profile, $key, $region] = [$options->restaurant_paytabs_profile_id ?? '', $options->restaurant_paytabs_secret_key ?? '', $options->restaurant_paytabs_region ?? ''];
        } else {
            [$profile, $key, $region] = [config('settings.paytabs_profile_id'), config('settings.paytabs_secret_key'), config('settings.paytabs_region')];
        }

        $region = strtoupper(trim((string) $region));
        if (! isset(self::REGIONS[$region]) || trim((string) $profile) === '' || trim((string) $key) === '') {
            // The store form's region is free text: an unknown one used to be a 500.
            throw new RuntimeException(___('This payment method is not configured.'));
        }

        return ['profile_id' => trim((string) $profile), 'server_key' => trim((string) $key), 'base' => self::REGIONS[$region]];
    }

    private function answer(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $status], $status);
    }
}
