<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\AccountPages;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * #65 Tap Payments (tap.company) — MENA (KSA/UAE/KW/BH). P1-23 part 5: moved from the hand-written TapController into
 * the adapter family, same wire protocol (the routes, the return URL `ipn/tap?txn=<id>` and the stored
 * `details.tap_charge_id` are unchanged, so a charge opened by the old controller still settles here).
 *
 * initiate: POST /v2/charges with the transaction's own key and currency, `reference.transaction` = our id; the
 * charge id is kept on the transaction; the buyer goes to `transaction.url`.
 * handleReturn (Tap appends `tap_id`): nothing in the request is trusted. The pending transaction named by `txn` is
 * loaded first and the charge is read with THAT transaction's key — the STORE's for orders and store payments (gift
 * cards, top-ups, deposits; {@see Settlement::usesStoreGateway()}), the platform's for plans/add-ons; no key =
 * refused. The charge must be the one opened for it, CAPTURED, for its exact amount and currency, in the key's mode
 * (`live_mode`), then it settles once (claim).
 * Phase 4 item 18: the signed charge POST (`post.url` → webhook/tap, {@see handleWebhook()}) settles a buyer who never
 * returns; refunds go through POST /v2/refunds ({@see refund()}); a test key only while payment sandboxes are allowed.
 */
class TapAdapter implements GatewayAdapter
{
    private const BASE = 'https://api.tap.company/v2';

    public function key(): string
    {
        return 'tap';
    }

    public function initiate(Transaction $transaction): Response
    {
        $secret = $this->secret($transaction);
        $currency = Settlement::currency($transaction);
        $cancelUrl = Settlement::cancelUrl($transaction);
        if ($secret === '') {
            app_alert_error(___('This payment method is not configured.'));

            return back();
        }

        try {
            $res = Http::withToken($secret)->acceptJson()->post(self::BASE.'/charges', [
                // Currency-aware precision: fils currencies (KWD/BHD/OMR) keep 3 decimals — see GatewayMinorUnits.
                'amount' => GatewayMinorUnits::majorFloatFromMoney($transaction->money('amount'), $currency),
                'currency' => $currency,
                'customer' => ['email' => GatewayBuyer::of($transaction)['email'] ?: 'guest@example.com'],
                'source' => ['id' => 'src_all'],
                'reference' => ['transaction' => (string) $transaction->id],
                // WI-15-sec: the return names our transaction, so handleReturn() can load it (and its own key) first.
                'redirect' => ['url' => route('ipn', ['gateway' => 'tap', 'txn' => $transaction->id])],
                // Phase 4 item 18: Tap posts the charge here too (signed `hashstring`), so a buyer who never comes back
                // is still settled.
                'post' => ['url' => route('webhook', 'tap')],
            ]);
            $url = $res->json('transaction.url');
            $chargeId = $res->json('id');
        } catch (\Throwable $e) {
            Log::error('[tap] charge create failed: '.$e->getMessage());
            $url = null;
            $chargeId = null;
        }

        if (! is_string($url) || $url === '') {
            app_alert_error(___('Could not start the payment. Please try again.'));

            return redirect($cancelUrl);
        }

        // WI-15-sec: remember the charge Tap opened for this transaction.
        if (is_string($chargeId) && $chargeId !== '') {
            $details = (array) ($transaction->details ?? []);
            $details['tap_charge_id'] = $chargeId;
            $transaction->forceFill(['details' => $details])->save();
        }

        return redirect()->away($url);
    }

    public function handleReturn(Request $request): Response
    {
        $chargeId = $request->get('tap_id');
        $transaction = $this->pendingTransaction($request->get('txn'));
        if (! is_string($chargeId) || $chargeId === '' || $transaction === null) {
            return Settlement::invalid();
        }

        $secret = $this->secret($transaction);
        $currency = Settlement::currency($transaction);
        $storedChargeId = data_get($transaction->details, 'tap_charge_id');
        if ($secret === '' || ($storedChargeId !== null && $storedChargeId !== $chargeId)) {
            Log::warning("[tap] refused charge {$chargeId} for transaction {$transaction->id}: no key or not its charge");
            app_alert_error(___('Payment failed, please try again.'));

            return redirect()->to(AccountPages::url());
        }

        $data = $this->fetchCharge($chargeId, $secret);

        if (! $this->chargePays($data, $chargeId, $transaction, $currency, $secret)) {
            Log::info("[tap] rejected charge {$chargeId} for transaction {$transaction->id}");

            return Settlement::failed();
        }

        // J25-7: claim + durable credit (outbox), then the order hand-off page or success + redirect (store payments
        // back to their store).
        return Settlement::settleForBuyer($transaction, 'tap', $chargeId, 'subscription', claim: true) ?? Settlement::invalid();
    }

    /**
     * Phase 4 item 18: Tap's POST of a charge to `post.url`. Verified before anything else with the `hashstring` header
     * — HMAC-SHA256, keyed with the secret key of the account the transaction is paid into, over
     * `x_id…x_amount…x_currency…x_gateway_reference…x_payment_reference…x_status…x_created…` (Tap "Webhook" docs; the
     * amount at the currency's decimals). Unsigned, wrongly signed, or a test-account post while sandboxes are off →
     * 400. Then once per (charge, status, body) (WebhookReceipts: a replayed copy is a 200 no-op), the charge is re-read
     * from Tap and settles under the same checks as the return, claimed once.
     */
    public function handleWebhook(Request $request): Response
    {
        $charge = json_decode($request->getContent(), true);
        $transaction = is_array($charge) ? $this->transactionOf(data_get($charge, 'reference.transaction')) : null;
        $secret = $transaction !== null ? $this->secret($transaction) : '';
        if (! is_array($charge) || $transaction === null || $secret === '') {
            PaymentLog::webhookRejected('tap', 'unknown charge or no key');

            return response()->json(['message' => 'Unknown charge.'], 400);
        }
        $signature = (string) $request->header('hashstring', '');
        if ($signature === '' || ! hash_equals(self::hashString($charge, $secret), strtolower($signature))) {
            PaymentLog::webhookRejected('tap', 'invalid signature');

            return response()->json(['message' => 'Invalid Tap signature.'], 400);
        }
        if (! GatewaySandbox::allowsEvent('tap', data_get($charge, 'live_mode') !== false)) {
            PaymentLog::webhookRejected('tap', 'test-mode charge while sandboxes are off');

            return response()->json(['message' => 'Test-mode charges are not accepted.'], 400);
        }

        $chargeId = (string) data_get($charge, 'id', '');
        $eventKey = WebhookReceipts::key($chargeId, data_get($charge, 'status'), WebhookReceipts::fingerprint($request));

        return WebhookReceipts::once('tap', $eventKey, function () use ($transaction, $chargeId, $secret) {
            if ($transaction->status !== null) {
                return response()->json(['message' => 'already settled'], 200);
            }
            $data = $this->fetchCharge($chargeId, $secret);
            if (strtoupper((string) data_get($data, 'status')) !== 'CAPTURED') {
                return response()->json(['message' => 'not paid'], 200); // declined / failed card: nothing to settle
            }
            $stored = data_get($transaction->details, 'tap_charge_id');
            if (($stored !== null && $stored !== $chargeId) || ! $this->chargePays($data, $chargeId, $transaction, Settlement::currency($transaction), $secret)) {
                Log::info("[tap] webhook charge {$chargeId} does not pay transaction {$transaction->id}");

                return response()->json(['message' => 'not settled'], 200);
            }
            try {
                Settlement::settle($transaction, 'tap', $chargeId, claim: true);
            } catch (\Throwable $e) {
                PaymentLog::settlementFailed('tap', $e);

                return response()->json(['message' => 'settlement failed'], 500); // released: Tap's retry tries again
            }

            return response()->json(['message' => 'successful'], 200);
        }, fn () => response()->json(['message' => 'duplicate'], 200));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * Phase 4 item 18: POST /v2/refunds on the charge, with the transaction's own key, through RefundGuard (own currency,
     * never more than captured minus refunded, exact at Tap's decimals). Tap answers PENDING/IN_PROGRESS while the
     * acquirer processes it, REFUNDED when done — both are accepted refunds; FAILED/DECLINED/CANCELLED are not.
     * Registering Tap for automatic store refunds (config refunds.adapters) stays the owner's decision (2026-09-27).
     */
    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        $own = Settlement::currency($transaction);

        return RefundGuard::run($transaction, $amount, $currency, $this->key(), GatewayMinorUnits::decimals($own), function (Money $money) use ($transaction) {
            $secret = $this->secret($transaction);
            $chargeId = (string) ($transaction->payment_id ?: data_get($transaction->details, 'tap_charge_id', ''));
            if ($secret === '' || ! str_starts_with($chargeId, 'chg_')) {
                return ['ok' => false, 'reference' => null, 'message' => 'No Tap charge to refund.'];
            }
            try {
                $refund = Http::withToken($secret)->acceptJson()->timeout(30)->post(self::BASE.'/refunds', [
                    'charge_id' => $chargeId,
                    'amount' => GatewayMinorUnits::majorFloatFromMoney($money, $money->currency()),
                    'currency' => $money->currency(),
                    'reason' => 'requested_by_customer',
                    'reference' => ['merchant' => 'txn-'.$transaction->id],
                ]);
            } catch (ConnectionException $e) {
                throw $e; // no answer: outcome unknown — RefundGuard keeps the reservation
            }
            $status = strtoupper((string) $refund->json('status'));
            if ($refund->successful() && in_array($status, ['REFUNDED', 'PENDING', 'IN_PROGRESS', 'INITIATED'], true)) {
                return ['ok' => true, 'reference' => (string) $refund->json('id'), 'message' => null];
            }

            return ['ok' => false, 'reference' => null, 'message' => (string) ($refund->json('errors.0.description') ?? $refund->json('response.message') ?? 'Tap refused the refund.')];
        });
    }

    /**
     * Tap's `hashstring` of a charge (docs: "Webhook" → validate the hashstring): HMAC-SHA256 with the secret key over
     * x_id, x_amount (at the currency's decimals), x_currency, x_gateway_reference, x_payment_reference, x_status,
     * x_created. Public for the test vectors.
     *
     * @param  array<string, mixed>  $charge
     */
    public static function hashString(array $charge, string $secret): string
    {
        $currency = strtoupper((string) data_get($charge, 'currency', ''));
        $amount = data_get($charge, 'amount', 0);
        $amount = number_format(is_numeric($amount) ? (float) $amount : 0.0, GatewayMinorUnits::decimals($currency), '.', '');
        $message = 'x_id'.data_get($charge, 'id', '')
            .'x_amount'.$amount
            .'x_currency'.$currency
            .'x_gateway_reference'.data_get($charge, 'reference.gateway', '')
            .'x_payment_reference'.data_get($charge, 'reference.payment', '')
            .'x_status'.data_get($charge, 'status', '')
            .'x_created'.data_get($charge, 'transaction.created', '');

        return hash_hmac('sha256', $message, $secret);
    }

    /* ------------------------------------------------------------ internals */

    /** Is this retrieved charge the captured payment of this transaction? */
    private function chargePays(mixed $charge, string $chargeId, Transaction $transaction, string $currency, string $secret): bool
    {
        if (! is_array($charge)) {
            return false;
        }

        // Tap test keys are sk_test_…; anything else is treated as live.
        $liveKey = ! str_starts_with($secret, 'sk_test_');
        $paid = data_get($charge, 'amount');
        $paid = is_int($paid) || is_float($paid) || is_string($paid) ? $paid : '';

        return data_get($charge, 'id') === $chargeId
            && (string) data_get($charge, 'reference.transaction') === (string) $transaction->id
            && strtoupper((string) data_get($charge, 'status')) === 'CAPTURED'
            && $currency !== ''
            // Exact at Tap's decimals (KWD 3), in our currency — Settlement::amountMatches logs a mismatch.
            && Settlement::amountMatches($transaction, $paid, (string) data_get($charge, 'currency'), 'tap', $currency)
            && strtoupper((string) data_get($charge, 'currency')) === strtoupper($currency)
            && data_get($charge, 'live_mode') === $liveKey;
    }

    /** The secret key of the account this transaction is paid into: the store's (orders, store payments) or the platform's. */
    private function secret(Transaction $transaction): string
    {
        $secret = Settlement::usesStoreGateway($transaction)
            ? (store_options($transaction->store_id)->restaurant_tap_secret_key ?? null)
            : config('settings.tap_secret_key');
        $secret = is_string($secret) ? trim($secret) : '';

        // Phase 4 item 18: a test key (sk_test_…) only while payment sandboxes are allowed.
        return GatewaySandbox::allowsKey('tap', $secret) ? $secret : '';
    }

    /** The charge as Tap holds it (null when unreadable). */
    private function fetchCharge(string $chargeId, string $secret): mixed
    {
        try {
            return Http::withToken($secret)->acceptJson()->timeout(30)->get(self::BASE.'/charges/'.rawurlencode($chargeId))->json();
        } catch (\Throwable $e) {
            Log::error('[tap] verify failed: '.$e->getMessage());

            return null;
        }
    }

    /** The transaction a webhook names (any status: a settled one answers "duplicate" after its signature is checked). */
    private function transactionOf(mixed $reference): ?Transaction
    {
        if (! is_scalar($reference) || ! ctype_digit((string) $reference)) {
            return null;
        }

        return Transaction::query()->whereKey((int) $reference)->first();
    }

    private function pendingTransaction(mixed $reference): ?Transaction
    {
        if (! is_scalar($reference) || ! ctype_digit((string) $reference)) {
            return null;
        }

        return Transaction::where('id', $reference)->whereNull('status')->whereNull('payment_id')->first();
    }
}
