<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telr Hosted Payment Page (https://docs.telr.com/reference/hosted-payment-page).
 *
 * initiate: `create` an order (cartid = our transaction id, test flag from the
 * sandbox switch), keep Telr's order `ref` as the transaction's payment_id and
 * send the buyer to the order `url`. Every way back — authorised, declined,
 * cancelled — lands on `ipn/telr?order_id=<tx>`, which asks Telr (`check`,
 * authenticated with the store id + auth key) and settles only status code 3
 * (paid) for our cart, amount and currency. Telr's transaction advice
 * (`webhook/telr`) is not trusted as such: it only names the cart, and the same
 * authenticated `check` decides, so a forged advice cannot settle anything.
 *
 * Keys: store orders use the store's own Telr store id / auth key (BYOK),
 * everything else the platform's.
 */
class TelrAdapter implements GatewayAdapter
{
    public const ENDPOINT = 'https://secure.telr.com/gateway/order.json';

    private const PAID = 3;

    public function key(): string
    {
        return 'telr';
    }

    public function initiate(Transaction $transaction): Response
    {
        try {
            $creds = $this->credentials($transaction);
            if ($creds['store'] === '' || $creds['authkey'] === '') {
                throw new RuntimeException(___('This payment method is not configured.'));
            }

            $currency = Settlement::currency($transaction, 'AED');
            $back = route('ipn', 'telr').'?order_id='.$transaction->id;
            $result = $this->call($creds, [
                'method' => 'create',
                'order' => [
                    'cartid' => (string) $transaction->id,
                    'test' => $creds['test'] ? 1 : 0,
                    'amount' => GatewayMinorUnits::majorStringFromMoney($transaction->money('amount'), $currency),
                    'currency' => $currency,
                    'description' => Settlement::title($transaction),
                ],
                'return' => ['authorised' => $back, 'declined' => $back.'&outcome=declined', 'cancelled' => $back.'&outcome=cancelled'],
            ]);

            if (isset($result['error']) || empty($result['order']['ref']) || empty($result['order']['url'])) {
                $message = (string) ($result['error']['message'] ?? ___('Payment failed, please try again.'));
                Log::info('Telr order refused: '.$message);
                app_alert_error($message);

                return back()->withInput();
            }

            $transaction->update(['payment_id' => (string) $result['order']['ref']]);

            return redirect()->away((string) $result['order']['url']);
        } catch (\Throwable $e) {
            PaymentLog::failure('telr', $e, ['transaction_id' => $transaction->id]);
            app_alert_error($e->getMessage());

            return back()->withInput();
        }
    }

    public function handleReturn(Request $request): Response
    {
        $id = (string) $request->input('order_id', '');
        $transaction = ctype_digit($id) ? Settlement::pending(['id' => $id]) : null;
        if ($transaction === null || (string) $transaction->payment_id === '') {
            return Settlement::invalid();
        }

        try {
            if ($this->confirmed($transaction)) {
                // J25-7: credit durable via the outbox, same answer to the buyer.
                return Settlement::settleForBuyer($transaction, 'telr', null);
            }
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('telr', $e);
        }

        return $this->failedBack($transaction, $request->input('outcome') === 'cancelled');
    }

    public function handleWebhook(Request $request): Response
    {
        $cart = (string) ($request->input('tran_cartid') ?? $request->input('cartid') ?? '');
        $transaction = ctype_digit($cart) ? Settlement::pending(['id' => $cart]) : null;
        if ($transaction === null || (string) $transaction->payment_id === '') {
            return $this->answer(200, 'ignored');
        }

        try {
            if (! $this->confirmed($transaction)) {
                return $this->answer(200, 'not paid');
            }

            // J25-7: Telr's notification names only the cart; the verified event is "Telr confirmed this cart paid".
            return WebhookReceipts::once('telr', WebhookReceipts::key($transaction->id, 'paid'), function () use ($transaction) {
                Settlement::settle($transaction, 'telr'); // J25-7: credit durable via the outbox

                return $this->answer(200, 'successful');
            }, fn () => $this->answer(200, 'duplicate'));
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('telr', $e);

            return $this->answer(500, 'settlement failed');
        }
    }

    public function supportsRefund(): bool
    {
        // Telr refunds go through its remote API with a separate remote key the settings do not hold.
        return false;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        return ['ok' => false, 'reference' => null, 'message' => 'Telr refunds are made from the Telr merchant panel.'];
    }

    /* ------------------------------------------------------------ internals */

    /** Telr's `check` says the order is paid, for our cart, amount and currency. */
    private function confirmed(Transaction $transaction): bool
    {
        $order = $this->call($this->credentials($transaction), ['method' => 'check', 'order' => ['ref' => (string) $transaction->payment_id]])['order'] ?? null;
        if (! is_array($order) || (int) ($order['status']['code'] ?? 0) !== self::PAID || (string) ($order['cartid'] ?? '') !== (string) $transaction->id) {
            Log::info("Telr check for transaction {$transaction->id}: ".(is_array($order) ? ($order['status']['text'] ?? 'not paid') : 'no order'));

            return false;
        }

        return Settlement::amountMatches($transaction, (float) ($order['amount'] ?? 0), (string) ($order['currency'] ?? ''), 'telr', Settlement::currency($transaction, 'AED'));
    }

    /**
     * @param  array{store:string,authkey:string,test:bool}  $creds
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(array $creds, array $body): array
    {
        $response = Http::acceptJson()->asJson()->timeout(30)->post(self::ENDPOINT, [
            'store' => $creds['store'],
            'authkey' => $creds['authkey'],
            'framed' => 0,
        ] + $body);
        $json = $response->json();

        return is_array($json) ? $json : ['error' => ['message' => 'Telr answered HTTP '.$response->status()]];
    }

    /** The buyer did not pay: back to where they came from, not to Telr (a cross-site back()). */
    private function failedBack(Transaction $transaction, bool $cancelled): RedirectResponse
    {
        if (! $cancelled) {
            app_alert_error(___('Payment failed, please try again.'));
        }

        return redirect()->to(Settlement::cancelUrl($transaction));
    }

    /** @return array{store:string,authkey:string,test:bool} */
    private function credentials(Transaction $transaction): array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $options = store_options($transaction->store_id);
            [$store, $key, $mode] = [$options->restaurant_telr_store_id ?? '', $options->restaurant_telr_authkey ?? '', $options->restaurant_telr_sandbox_mode ?? ''];
        } else {
            [$store, $key, $mode] = [config('settings.telr_store_id'), config('settings.telr_authkey'), config('settings.telr_sandbox_mode')];
        }

        $sandbox = Settlement::isSandbox($mode);

        return GatewaySandbox::gate('telr', $sandbox, ['store' => trim((string) $store), 'authkey' => trim((string) $key), 'test' => $sandbox]);
    }

    private function answer(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $status], $status);
    }
}
