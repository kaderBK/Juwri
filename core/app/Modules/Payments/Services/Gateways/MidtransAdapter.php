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
use Illuminate\Support\Str;
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
 * Midtrans Snap (https://docs.midtrans.com/reference/backend-integration,
 * notifications: https://docs.midtrans.com/docs/https-notification-webhooks,
 * status: https://docs.midtrans.com/reference/get-transaction-status).
 *
 * initiate: create a Snap transaction (order_id = our transaction id, the
 * notification URL overridden to `webhook/midtrans`) and render our payment
 * page with the Snap popup. Midtrans notifies `webhook/midtrans` on every
 * status change with `signature_key` = SHA-512 of order_id + status_code +
 * gross_amount + server key; after that check the status API is asked, and only
 * `settlement` (or a `capture` the fraud check accepted) for our amount settles,
 * once. The buyer's return (`ipn/midtrans`) asks the status API too and
 * settles the same way, otherwise shows "processing". Midtrans charges IDR only:
 * a transaction in another currency is refused before the call
 * (GatewayCurrencies::FIXED) and never settles.
 *
 * Keys: store orders use the store's own server/client key (BYOK), everything
 * else the platform's.
 */
class MidtransAdapter implements GatewayAdapter
{
    public function key(): string
    {
        return 'midtrans';
    }

    public function initiate(Transaction $transaction): Response
    {
        try {
            $creds = $this->credentials($transaction);
            if ($creds['server_key'] === '') {
                throw new RuntimeException(___('This payment method is not configured.'));
            }

            $store = Settlement::store($transaction);
            Settlement::useStoreCurrency($transaction);
            $amount = $this->grossAmount($transaction->money('amount'));
            if ($amount === null) { // GatewayCurrencies refuses this in pay() already; never round the customer's amount
                throw new RuntimeException((string) ___('This payment method only charges whole amounts in :currency, and this payment is :amount :currency. Please choose another payment method.', [
                    'currency' => 'IDR', 'amount' => GatewayMinorUnits::majorStringFromMoney($transaction->money('amount'), 'IDR'),
                ]));
            }
            $query = '?order_id='.$transaction->id;

            $snap = $this->client($creds, $creds['snap'])
                ->withHeaders(['X-Override-Notification' => url('webhook/midtrans').$query])
                ->post('/snap/v1/transactions', [
                    'transaction_details' => ['order_id' => (string) $transaction->id, 'gross_amount' => $amount],
                    'item_details' => [[
                        'id' => (string) ($transaction->product_id ?: $transaction->id),
                        'price' => $amount,
                        'quantity' => 1,
                        'name' => Str::limit(Settlement::title($transaction), 47),
                    ]],
                    'credit_card' => ['secure' => true],
                ]);
            $token = (string) $snap->json('token', '');
            if ($token === '') {
                $messages = (array) $snap->json('error_messages', []);
                throw new RuntimeException((string) ($messages[0] ?? ___('Payment failed, please try again.')));
            }

            return response()->view('owner.billing.gateways.midtrans', [
                'url' => $creds['snap'].'/snap/snap.js',
                'mt_client_key' => $creds['client_key'],
                'snapToken' => $token,
                'transaction' => $transaction,
                'return_url' => route('ipn', 'midtrans').$query,
                'cancel_url' => Settlement::cancelUrl($transaction),
                'color' => (string) ($store->color ?? config('settings.theme_color')),
            ]);
        } catch (\Throwable $e) {
            PaymentLog::failure('midtrans', $e, ['transaction_id' => $transaction->id]);
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

        if ($transaction->status === null) {
            try {
                $paymentId = $this->paidTransactionId($this->credentials($transaction), $transaction);
                if ($paymentId !== null) {
                    // J25-7: credit durable via the outbox, same answer to the buyer.
                    return Settlement::settleForBuyer($transaction, 'midtrans', $paymentId);
                }
            } catch (\Throwable $e) {
                PaymentLog::settlementFailed('midtrans', $e);
            }
        }

        return Settlement::processingForBuyer($transaction);
    }

    public function handleWebhook(Request $request): Response
    {
        $event = json_decode($request->getContent(), true);
        if (! is_array($event) || ! isset($event['order_id'])) {
            PaymentLog::webhookRejected('midtrans', 'invalid payload');

            return $this->answer(400, 'Invalid payload.');
        }

        $orderId = (string) $event['order_id'];
        $known = ctype_digit($orderId) ? Transaction::find((int) $orderId) : null;
        $creds = $known !== null ? $this->credentials($known) : $this->platformCredentials();
        $expected = hash('sha512', $orderId.($event['status_code'] ?? '').($event['gross_amount'] ?? '').$creds['server_key']);
        if ($creds['server_key'] === '' || ! hash_equals($expected, strtolower((string) ($event['signature_key'] ?? '')))) {
            PaymentLog::webhookRejected('midtrans', 'invalid signature');

            return $this->answer(400, 'Invalid Midtrans signature.');
        }

        if ($known === null || $known->status !== null) {
            return $this->answer(200, 'successful');
        }

        try {
            $paymentId = $this->paidTransactionId($creds, $known);
            if ($paymentId === null) {
                return $this->answer(200, 'not settled');
            }
            // J25-7: the verified event is "midtrans confirmed this payment paid" (checked with the provider above), so a repeated or concurrent confirmation is a no-op (WebhookReceipts) — a copy that arrives before the provider confirms is never dropped.
            if (! WebhookReceipts::once('midtrans', WebhookReceipts::key($known->id, 'paid'), function () use ($known, $paymentId) {
                Settlement::settle($known, 'midtrans', $paymentId); // J25-7: credit durable via the outbox

                return true;
            }, false)) {
                return $this->answer(200, 'duplicate');
            }
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('midtrans', $e);

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
        // Whole rupiah only (GatewayMinorUnits::GATEWAY_ZERO_DECIMAL): before: wire decimals 2, IDR 100.50 sent as 100.5.
        return RefundGuard::run($transaction, $amount, $currency, $this->key(), GatewayMinorUnits::gatewayDecimals($this->key(), 'IDR'), function (Money $money) use ($transaction) {
            try {
                $creds = $this->credentials($transaction);
                $key = 'refund-'.$transaction->id.'-'.now()->format('YmdHis');
                $gross = $this->grossAmount($money);
                if ($gross === null) {
                    return ['ok' => false, 'reference' => null, 'message' => (string) ___('The refund amount :amount cannot be refunded exactly in :currency on this payment method. Enter an amount the payment method supports.', [
                        'amount' => $money->major(), 'currency' => $money->currency(),
                    ])];
                }
                $result = $this->client($creds, $creds['api'])->post('/v2/'.rawurlencode((string) $transaction->id).'/refund', [
                    'refund_key' => $key,
                    'amount' => $gross,
                    'reason' => 'Refund '.$transaction->id,
                ])->json();
                if ((string) ($result['status_code'] ?? '') !== '200') {
                    return ['ok' => false, 'reference' => null, 'message' => (string) ($result['status_message'] ?? 'Refund refused')];
                }

                return ['ok' => true, 'reference' => (string) ($result['refund_key'] ?? $key), 'message' => null];
            } catch (ConnectionException $e) {
                throw $e; // no answer: outcome unknown — RefundGuard keeps the reservation (bug batch 6)
            } catch (\Throwable $e) {
                return ['ok' => false, 'reference' => null, 'message' => $e->getMessage()];
            }
        });
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Midtrans' transaction id when the status API says our order is paid in
     * full: `settlement`, or `capture` accepted by the fraud check. `pending`
     * (bank transfer, e-wallet not yet paid) is not paid.
     *
     * @param  array{server_key:string,client_key:string,snap:string,api:string}  $creds
     */
    private function paidTransactionId(array $creds, Transaction $transaction): ?string
    {
        $status = (array) $this->client($creds, $creds['api'])->get('/v2/'.rawurlencode((string) $transaction->id).'/status')->json();
        $state = (string) ($status['transaction_status'] ?? '');
        $fraud = (string) ($status['fraud_status'] ?? 'accept');
        $paid = $state === 'settlement' || ($state === 'capture' && $fraud === 'accept');

        if (! $paid || (string) ($status['order_id'] ?? '') !== (string) $transaction->id) {
            Log::info("Midtrans status for transaction {$transaction->id}: {$state}");

            return null;
        }
        if (! Settlement::amountMatches($transaction, (float) ($status['gross_amount'] ?? 0), (string) (($status['currency'] ?? '') ?: 'IDR'), 'midtrans', Settlement::currency($transaction, 'IDR'))) {
            return null;
        }

        return (string) ($status['transaction_id'] ?? '') ?: null;
    }

    /**
     * The amount as Midtrans' integer `gross_amount` (whole rupiah — GatewayMinorUnits::GATEWAY_ZERO_DECIMAL), from the
     * stored minor units; null when it has a fraction Midtrans cannot carry. before: a fractional amount was sent as a
     * 2-decimal float (IDR 15000.50 → 15000.5), which Midtrans rejects.
     */
    private function grossAmount(?Money $money): ?int
    {
        $value = GatewayMinorUnits::gatewayMajorString($this->key(), $money, 'IDR');

        return $value === null ? null : (int) $value;
    }

    /** @param  array{server_key:string,client_key:string,snap:string,api:string}  $creds */
    private function client(array $creds, string $base): PendingRequest
    {
        return Http::baseUrl($base)->withBasicAuth($creds['server_key'], '')->asJson()->acceptJson()->timeout(30);
    }

    /** @return array{server_key:string,client_key:string,snap:string,api:string} */
    private function credentials(Transaction $transaction): array
    {
        if (! Settlement::usesStoreGateway($transaction)) {
            return $this->platformCredentials();
        }
        $options = store_options($transaction->store_id);

        // The store forms save restaurant_midtrans_*; the old driver read the un-prefixed keys
        // (so store payments could never work) — those are still honoured as a fallback.
        return $this->creds(
            ($options->restaurant_midtrans_server_key ?? '') ?: ($options->midtrans_server_key ?? ''),
            ($options->restaurant_midtrans_client_key ?? '') ?: ($options->midtrans_client_key ?? ''),
            ($options->restaurant_midtrans_sandbox_mode ?? '') ?: ($options->midtrans_sandbox_mode ?? ''),
        );
    }

    /** @return array{server_key:string,client_key:string,snap:string,api:string} */
    private function platformCredentials(): array
    {
        return $this->creds(config('settings.midtrans_server_key'), config('settings.midtrans_client_key'), config('settings.midtrans_sandbox_mode'));
    }

    /** @return array{server_key:string,client_key:string,snap:string,api:string} */
    private function creds(mixed $serverKey, mixed $clientKey, mixed $mode): array
    {
        $sandbox = Settlement::isSandbox($mode);

        return GatewaySandbox::gate('midtrans', $sandbox, [
            'server_key' => trim((string) $serverKey),
            'client_key' => trim((string) $clientKey),
            'snap' => $sandbox ? 'https://app.sandbox.midtrans.com' : 'https://app.midtrans.com',
            'api' => $sandbox ? 'https://api.sandbox.midtrans.com' : 'https://api.midtrans.com',
        ]);
    }

    private function answer(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $status], $status);
    }
}
