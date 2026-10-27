<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Client\ConnectionException;
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
 * Paytm Payment Gateway, redirect flow
 * (https://business.paytm.com/docs/api/initiate-transaction-api,
 * https://business.paytm.com/docs/show-payment-page,
 * https://business.paytm.com/docs/api/v3/transaction-status-api).
 *
 * initiate: Initiate Transaction API (signed JSON body) gives a txnToken; the
 * buyer's browser posts mid/orderId/txnToken to Paytm's payment page. Paytm
 * posts the result back to `ipn/paytm` with a CHECKSUMHASH over the posted
 * fields. The return settles only when that checksum verifies AND the signed
 * Transaction Status API says TXN_SUCCESS for our order id and amount. The
 * same form-encoded notification can be configured as Paytm's payment webhook
 * (`webhook/paytm`); it follows the same checks and answers JSON.
 *
 * Keys: store orders use the store's merchant id/key/website (BYOK), the rest
 * the platform's. Amounts are INR with two decimals; a transaction in another
 * currency is refused before the call (GatewayCurrencies::FIXED) and never settles.
 */
class PaytmAdapter implements GatewayAdapter
{
    public const LIVE = 'https://securegw.paytm.in';

    public const STAGING = 'https://securegw-stage.paytm.in';

    public function key(): string
    {
        return 'paytm';
    }

    public function initiate(Transaction $transaction): Response
    {
        try {
            $creds = $this->credentials($transaction);
            if ($creds['mid'] === '' || $creds['key'] === '') {
                throw new RuntimeException(___('This payment method is not configured.'));
            }

            $orderId = (string) $transaction->id;
            $buyer = request()->user();
            $userInfo = ['custId' => Settlement::usesStoreGateway($transaction)
                ? 'store-'.$transaction->store_id
                : ($buyer !== null ? (string) $buyer->id : 'guest-'.$transaction->id)];
            if (! Settlement::usesStoreGateway($transaction) && $buyer !== null && (string) $buyer->email !== '') {
                $userInfo['email'] = (string) $buyer->email;
            }

            $body = [
                'requestType' => 'Payment',
                'mid' => $creds['mid'],
                'websiteName' => $creds['website'] !== '' ? $creds['website'] : 'DEFAULT',
                'orderId' => $orderId,
                'callbackUrl' => route('ipn', 'paytm'),
                'txnAmount' => ['value' => $this->amount($transaction->amount), 'currency' => 'INR'],
                'userInfo' => $userInfo,
            ];
            $query = '?mid='.rawurlencode($creds['mid']).'&orderId='.rawurlencode($orderId);
            $result = $this->signedPost($creds, '/theia/api/v1/initiateTransaction'.$query, $body);

            $token = (string) ($result['body']['txnToken'] ?? '');
            if (($result['body']['resultInfo']['resultStatus'] ?? null) !== 'S' || $token === '') {
                $message = (string) ($result['body']['resultInfo']['resultMsg'] ?? ___('Payment failed, please try again.'));
                Log::info('Paytm initiate refused: '.$message);
                app_alert_error($message);

                return back()->withInput();
            }

            return response()->view('owner.billing.gateways.redirect-form', [
                'url' => $creds['base'].'/theia/api/v1/showPaymentPage'.$query,
                'data' => ['mid' => $creds['mid'], 'orderId' => $orderId, 'txnToken' => $token],
            ]);
        } catch (\Throwable $e) {
            PaymentLog::failure('paytm', $e, ['transaction_id' => $transaction->id]);
            app_alert_error($e->getMessage());

            return back()->withInput();
        }
    }

    public function handleReturn(Request $request): Response
    {
        $fields = $request->post();
        $orderId = (string) ($fields['ORDERID'] ?? '');
        $transaction = $orderId === '' ? null : Settlement::pending(['id' => $orderId], withoutPaymentId: true);
        if ($transaction === null) {
            return Settlement::invalid();
        }

        try {
            $creds = $this->credentials($transaction);
            if (! PaytmChecksum::verify($fields, $creds['key'], (string) ($fields['CHECKSUMHASH'] ?? ''))) {
                Log::warning("Paytm return with a bad checksum for transaction {$transaction->id}");

                return Settlement::failed();
            }
            if (($fields['STATUS'] ?? null) !== 'TXN_SUCCESS') {
                $reason = (string) ($fields['RESPMSG'] ?? '');
                Log::info('Paytm Payment failed: '.$reason);

                return Settlement::failed($reason);
            }

            $txnId = $this->confirmedTxnId($creds, $transaction);
            if ($txnId === null) {
                return Settlement::failed();
            }

            // J25-7: credit durable via the outbox, same answer to the buyer.
            return Settlement::settleForBuyer($transaction, 'paytm', $txnId);
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('paytm', $e);

            return Settlement::failed();
        }
    }

    public function handleWebhook(Request $request): Response
    {
        $fields = $request->post();
        $orderId = (string) ($fields['ORDERID'] ?? '');
        $transaction = $orderId === '' ? null : Settlement::pending(['id' => $orderId], withoutPaymentId: true);

        // The order id only picks which merchant key to check; nothing is trusted before the check.
        $known = $transaction ?? (ctype_digit($orderId) ? Transaction::find((int) $orderId) : null);
        $creds = $known !== null ? $this->credentials($known) : $this->platformCredentials();
        if (! PaytmChecksum::verify($fields, $creds['key'], (string) ($fields['CHECKSUMHASH'] ?? ''))) {
            PaymentLog::webhookRejected('paytm', 'invalid signature');

            return $this->answer(400, 'Invalid Paytm checksum.');
        }

        if ($transaction === null || ($fields['STATUS'] ?? null) !== 'TXN_SUCCESS') {
            return $this->answer(200, 'successful');
        }

        try {
            $txnId = $this->confirmedTxnId($creds, $transaction);
            if ($txnId === null) {
                return $this->answer(200, 'not confirmed');
            }
            // J25-7: the verified event is "paytm confirmed this payment paid" (checked with the provider above), so a repeated or concurrent confirmation is a no-op (WebhookReceipts) — a copy that arrives before the provider confirms is never dropped.
            if (! WebhookReceipts::once('paytm', WebhookReceipts::key($transaction->id, 'paid'), function () use ($transaction, $txnId) {
                Settlement::settle($transaction, 'paytm', $txnId); // J25-7: credit durable via the outbox

                return true;
            }, false)) {
                return $this->answer(200, 'duplicate');
            }
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('paytm', $e);

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
                $creds = $this->credentials($transaction);
                $refId = 'refund-'.$transaction->id.'-'.now()->format('YmdHis');
                $result = $this->signedPost($creds, '/refund/apply', [
                    'mid' => $creds['mid'],
                    'txnType' => 'REFUND',
                    'orderId' => (string) $transaction->id,
                    'txnId' => (string) $transaction->payment_id,
                    'refId' => $refId,
                    'refundAmount' => GatewayMinorUnits::majorStringFromMoney($money, 'INR'),
                ]);
                $status = (string) ($result['body']['resultInfo']['resultStatus'] ?? '');
                if (! in_array($status, ['PENDING', 'TXN_SUCCESS'], true)) {
                    return ['ok' => false, 'reference' => null, 'message' => (string) ($result['body']['resultInfo']['resultMsg'] ?? 'Refund refused')];
                }

                return ['ok' => true, 'reference' => (string) ($result['body']['refundId'] ?? $refId), 'message' => null];
            } catch (ConnectionException $e) {
                throw $e; // no answer: outcome unknown — RefundGuard keeps the reservation (bug batch 6)
            } catch (\Throwable $e) {
                return ['ok' => false, 'reference' => null, 'message' => $e->getMessage()];
            }
        });
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Ask Paytm (signed Transaction Status API) whether our order is paid in
     * full; the provider's transaction id when it is.
     *
     * @param  array{mid:string,key:string,website:string,base:string}  $creds
     */
    private function confirmedTxnId(array $creds, Transaction $transaction): ?string
    {
        $status = $this->signedPost($creds, '/v3/order/status', ['mid' => $creds['mid'], 'orderId' => (string) $transaction->id])['body'] ?? [];

        if (($status['resultInfo']['resultStatus'] ?? null) !== 'TXN_SUCCESS'
            || (string) ($status['orderId'] ?? '') !== (string) $transaction->id) {
            Log::info("Paytm status for transaction {$transaction->id}: ".($status['resultInfo']['resultMsg'] ?? 'not successful'));

            return null;
        }
        if (! Settlement::amountMatches($transaction, (float) ($status['txnAmount'] ?? 0), 'INR', 'paytm', Settlement::currency($transaction, 'INR'))) {
            return null;
        }

        return (string) ($status['txnId'] ?? '') ?: null;
    }

    /**
     * POST {body, head.signature} where the signature covers the exact JSON sent.
     *
     * @param  array{mid:string,key:string,website:string,base:string}  $creds
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function signedPost(array $creds, string $path, array $body): array
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $payload = '{"body":'.$json.',"head":{"signature":'.json_encode(PaytmChecksum::generate($json, $creds['key'])).'}}';

        $response = Http::withBody($payload, 'application/json')->acceptJson()->timeout(30)
            ->post($creds['base'].$path)->throw();

        return (array) $response->json();
    }

    /** @return array{mid:string,key:string,website:string,base:string} */
    private function credentials(Transaction $transaction): array
    {
        if (! Settlement::usesStoreGateway($transaction)) {
            return $this->platformCredentials();
        }
        $options = store_options($transaction->store_id);

        return $this->creds(
            $options->restaurant_paytm_merchant_mid ?? '',
            $options->restaurant_paytm_merchant_key ?? '',
            $options->restaurant_paytm_merchant_website ?? '',
            $options->restaurant_paytm_sandbox_mode ?? '',
        );
    }

    /** @return array{mid:string,key:string,website:string,base:string} */
    private function platformCredentials(): array
    {
        return $this->creds(
            config('settings.PAYTM_MERCHANT_MID'),
            config('settings.PAYTM_MERCHANT_KEY'),
            config('settings.PAYTM_MERCHANT_WEBSITE'),
            config('settings.PAYTM_ENVIRONMENT'),
        );
    }

    /** @return array{mid:string,key:string,website:string,base:string} */
    private function creds(mixed $mid, mixed $key, mixed $website, mixed $mode): array
    {
        $sandbox = Settlement::isSandbox($mode);

        return GatewaySandbox::gate('paytm', $sandbox, [
            'mid' => trim((string) $mid),
            'key' => trim((string) $key),
            'website' => trim((string) $website),
            'base' => $sandbox ? self::STAGING : self::LIVE,
        ]);
    }

    /** Paytm `txnAmount.value`: INR major-unit decimal string ("12.50"). */
    private function amount(mixed $amount): string
    {
        return GatewayMinorUnits::toMajorString($amount, 'INR');
    }

    private function answer(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $status], $status);
    }
}
