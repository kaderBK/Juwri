<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * CCAvenue non-seamless checkout (https://www.ccavenue.com/ integration kit).
 *
 * The request is a query string encrypted with the merchant's working key —
 * AES-128-CBC, key = binary MD5 of the working key, IV = bytes 0x00..0x0f,
 * hex encoded — posted with the access code to CCAvenue's initiateTransaction
 * page. CCAvenue posts `encResp` back to the redirect (and cancel) URL
 * `ipn/ccavenue?order_id=<tx>`; only a payload that decrypts under the right
 * working key, names the same order, says `Success` and carries our amount and
 * currency settles, once. The optional merchant notification (same encResp)
 * can be pointed at `webhook/ccavenue`.
 *
 * Keys: store orders use the store's merchant id / access code / working key
 * (BYOK), everything else the platform's.
 */
class CcavenueAdapter implements GatewayAdapter
{
    public const ENDPOINT = 'https://secure.ccavenue.com/transaction/transaction.do?command=initiateTransaction';

    public function key(): string
    {
        return 'ccavenue';
    }

    public function initiate(Transaction $transaction): Response
    {
        try {
            $creds = $this->credentials($transaction);
            if ($creds['merchant_id'] === '' || $creds['access_code'] === '' || $creds['working_key'] === '') {
                throw new RuntimeException(___('This payment method is not configured.'));
            }

            $currency = Settlement::currency($transaction, 'INR');
            $back = route('ipn', 'ccavenue').'?order_id='.$transaction->id;
            $request = http_build_query([
                'merchant_id' => $creds['merchant_id'],
                'order_id' => (string) $transaction->id,
                'amount' => GatewayMinorUnits::majorStringFromMoney($transaction->money('amount'), $currency),
                'currency' => $currency,
                'redirect_url' => $back,
                'cancel_url' => $back,
                'language' => (string) get_lang(),
            ]);

            return response()->view('owner.billing.gateways.redirect-form', [
                'url' => self::ENDPOINT,
                'data' => ['encRequest' => self::encrypt($request, $creds['working_key']), 'access_code' => $creds['access_code']],
            ]);
        } catch (\Throwable $e) {
            PaymentLog::failure('ccavenue', $e, ['transaction_id' => $transaction->id]);
            app_alert_error($e->getMessage());

            return back()->withInput();
        }
    }

    public function handleReturn(Request $request): Response
    {
        $id = (string) $request->input('order_id', '');
        if ($id === '') {
            // Plan checkouts started before order_id was added to the return URL: the
            // order id is inside the payload encrypted with the platform key.
            $plain = self::decrypt((string) $request->input('encResp', ''), trim((string) config('settings.CCAVENUE_WORKING_KEY')));
            parse_str((string) $plain, $fields);
            $id = is_string($fields['order_id'] ?? null) ? $fields['order_id'] : '';
        }
        $transaction = ctype_digit($id) ? Settlement::pending(['id' => $id], withoutPaymentId: true) : null;
        if ($transaction === null) {
            return Settlement::invalid();
        }

        try {
            $result = $this->decode($request, $transaction);
            if ($result === null) {
                Log::warning("CCAvenue return for transaction {$transaction->id} did not decrypt");

                return $this->failedBack($transaction, false);
            }
            if (($result['order_status'] ?? null) !== 'Success') {
                Log::info('CCAvenue Payment failed: '.($result['order_status'] ?? '').' '.($result['failure_message'] ?? $result['status_message'] ?? ''));

                return $this->failedBack($transaction, ($result['order_status'] ?? null) === 'Aborted');
            }
            if (! $this->paidInFull($result, $transaction)) {
                return $this->failedBack($transaction, false);
            }

            // J25-7: credit durable via the outbox, same answer to the buyer.
            return Settlement::settleForBuyer($transaction, 'ccavenue', (string) ($result['tracking_id'] ?? '') ?: null);
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('ccavenue', $e);

            return $this->failedBack($transaction, false);
        }
    }

    public function handleWebhook(Request $request): Response
    {
        $id = (string) $request->input('order_id', '');
        $transaction = ctype_digit($id) ? Settlement::pending(['id' => $id], withoutPaymentId: true) : null;
        $result = $transaction !== null ? $this->decode($request, $transaction) : null;
        if ($transaction !== null && $result === null) {
            PaymentLog::webhookRejected('ccavenue', 'invalid signature');

            return $this->answer(400, 'Invalid CCAvenue payload.');
        }

        // J25-7: once per verified event (WebhookReceipts): a re-delivered or concurrent copy is a 200 no-op.
        return WebhookReceipts::once('ccavenue', WebhookReceipts::key($result['tracking_id'] ?? null, $result['order_status'] ?? null, WebhookReceipts::fingerprint($request)), function () use ($transaction, $result) {
            if ($transaction === null || ($result['order_status'] ?? null) !== 'Success' || ! $this->paidInFull($result, $transaction)) {
                return $this->answer(200, 'successful');
            }

            try {
                Settlement::settle($transaction, 'ccavenue', (string) ($result['tracking_id'] ?? '') ?: null); // J25-7: credit durable via the outbox
            } catch (\Throwable $e) {
                PaymentLog::settlementFailed('ccavenue', $e);

                return $this->answer(500, 'settlement failed');
            }

            return $this->answer(200, 'successful');
        }, fn () => $this->answer(200, 'duplicate'));
    }

    public function supportsRefund(): bool
    {
        // CCAvenue's refund API needs its separate API access; refunds stay in the CCAvenue panel.
        return false;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        return ['ok' => false, 'reference' => null, 'message' => 'CCAvenue refunds are made from the CCAvenue merchant panel.'];
    }

    /** AES-128-CBC with CCAvenue's key derivation and IV, hex encoded. */
    public static function encrypt(string $plain, string $workingKey): string
    {
        return bin2hex((string) openssl_encrypt($plain, 'AES-128-CBC', md5($workingKey, true), OPENSSL_RAW_DATA, self::iv()));
    }

    public static function decrypt(string $hex, string $workingKey): ?string
    {
        if ($hex === '' || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            return null;
        }
        $plain = openssl_decrypt((string) hex2bin($hex), 'AES-128-CBC', md5($workingKey, true), OPENSSL_RAW_DATA, self::iv());

        return $plain === false ? null : $plain;
    }

    /* ------------------------------------------------------------ internals */

    private static function iv(): string
    {
        return implode('', array_map('chr', range(0, 15)));
    }

    /**
     * The decrypted response fields, when encResp decrypts under this
     * transaction's working key and names this order.
     *
     * @return array<string, string>|null
     */
    private function decode(Request $request, Transaction $transaction): ?array
    {
        $key = $this->credentials($transaction)['working_key'];
        $plain = $key === '' ? null : self::decrypt((string) $request->input('encResp', ''), $key);
        if ($plain === null) {
            return null;
        }
        parse_str($plain, $fields);

        return (string) ($fields['order_id'] ?? '') === (string) $transaction->id ? array_map('strval', array_filter($fields, 'is_scalar')) : null;
    }

    /** @param  array<string, string>  $result */
    private function paidInFull(array $result, Transaction $transaction): bool
    {
        return Settlement::amountMatches($transaction, (float) ($result['amount'] ?? $result['mer_amount'] ?? 0), $result['currency'] ?? '',
            'ccavenue', Settlement::currency($transaction, 'INR'));
    }

    /** CCAvenue posts back cross-site: send the buyer to the checkout's own page, not back(). */
    private function failedBack(Transaction $transaction, bool $cancelled): RedirectResponse
    {
        if (! $cancelled) {
            app_alert_error(___('Payment failed, please try again.'));
        }

        return redirect()->to(Settlement::cancelUrl($transaction));
    }

    /** @return array{merchant_id:string,access_code:string,working_key:string} */
    private function credentials(Transaction $transaction): array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $options = store_options($transaction->store_id);
            [$merchant, $access, $key] = [$options->restaurant_ccavenue_merchant_key ?? '', $options->restaurant_ccavenue_access_code ?? '', $options->restaurant_ccavenue_working_key ?? ''];
        } else {
            [$merchant, $access, $key] = [config('settings.CCAVENUE_MERCHANT_KEY'), config('settings.CCAVENUE_ACCESS_CODE'), config('settings.CCAVENUE_WORKING_KEY')];
        }

        return ['merchant_id' => trim((string) $merchant), 'access_code' => trim((string) $access), 'working_key' => trim((string) $key)];
    }

    private function answer(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $status], $status);
    }
}
