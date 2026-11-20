<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\GatewayAdapterController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaymentController;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\PaymentLog;

/**
 * P3-09 (REVIEW, Opus: money) — card holds of weighed baskets: which gateways can hold, and the one capture / void
 * of an authorised transaction.
 *
 *  - {@see capture()}: refused unless the transaction is `authorised`, the amount is > 0 and NOT above the authorised
 *    amount (`amount_minor`, the hold); the transaction is claimed `capturing` first (one conditional UPDATE), so two
 *    concurrent captures never both reach the provider. On success the transaction carries the captured amount and
 *    goes back to pending (status NULL) for the normal settlement (Settlement::settle → paySuccess), its authorised
 *    amount kept in `details.authorised_minor`. On a provider refusal it goes back to `authorised`.
 *  - {@see void()}: releases an `authorised` hold (status `voided`), nothing charged.
 *  - The E2E Stub gateway (local/testing with E2E_STUBS only) holds, captures and voids in-process.
 */
final class CardHolds
{
    public const STATUS_AUTHORISED = 'authorised';

    public const STATUS_CAPTURING = 'capturing';

    public const STATUS_VOIDED = 'voided';

    /** Can the gateway of this payment folder put a card hold and capture later? */
    public function authorises(string $folder): bool
    {
        if ($folder === 'stub') {
            return true;
        }
        $adapter = $this->adapterFor($folder);

        return $adapter instanceof AuthorisesPayments;
    }

    /**
     * Capture `$minor` (transaction currency) of an authorised hold. After an unknown outcome the retry is the stored
     * request (same key, same amount), whatever `$minor` it asks for: `captured_minor` is what was really captured.
     *
     * @return array{ok: bool, reference: string|null, message: string|null, captured_minor?: int}
     */
    public function capture(Transaction $transaction, int $minor): array
    {
        $currency = Settlement::currency($transaction, 'USD');
        $authorised = (int) $transaction->amount_minor;
        if ($minor <= 0 || $minor > $authorised) {
            return ['ok' => false, 'reference' => null, 'message' => 'The capture must be more than 0 and not above the authorised amount.'];
        }
        try {
            $gateway = (string) $transaction->transaction_gatway === 'stub' ? null : $this->gateway($transaction);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'reference' => null, 'message' => $e->getMessage()];
        }
        $claimed = Transaction::query()->whereKey($transaction->getKey())->where('status', self::STATUS_AUTHORISED)
            ->update(['status' => self::STATUS_CAPTURING]) === 1;
        if (! $claimed) {
            return ['ok' => false, 'reference' => null, 'message' => 'This card hold is not open for capture.'];
        }
        $transaction->refresh();
        // grocery-nits2: one idempotency key per capture request, a UUID stored on the transaction with the amount it
        // asks for BEFORE the provider is called (unique across environments and database resets that share a merchant
        // account). A retry after an unknown outcome (the call threw: a timeout, a dropped connection, a 5xx / 429)
        // sends the same key AND the same amount, because the provider answers it with the first request; only a
        // definite refusal clears it, so the next capture is a new request.
        $details = (array) ($transaction->details ?? []); // cast `object`: the nested capture_pending reads as an object
        $pendingKey = (string) data_get($details, 'capture_pending.key', '');
        $pendingMinor = (int) data_get($details, 'capture_pending.minor', 0);
        if ($pendingKey !== '' && $pendingMinor > 0 && $pendingMinor <= $authorised) {
            $idempotencyKey = $pendingKey;
            $minor = $pendingMinor;
        } else {
            $idempotencyKey = (string) Str::uuid();
            $details['capture_pending'] = ['key' => $idempotencyKey, 'minor' => $minor];
            $transaction->forceFill(['details' => $details])->save();
        }

        $unknown = false;
        try {
            $result = $gateway === null
                ? ['ok' => true, 'reference' => 'stub-capture-'.$transaction->getKey(), 'message' => null]
                : $gateway->capture($transaction, Money::ofMinor($minor, $currency), $idempotencyKey);
        } catch (\Throwable $e) {
            PaymentLog::failure((string) $transaction->transaction_gatway, $e, ['transaction_id' => $transaction->id, 'step' => 'capture']);
            $unknown = true;
            $result = ['ok' => false, 'reference' => null, 'message' => $e->getMessage()];
        }
        if ($unknown && $gateway !== null && ! $gateway->capturesIdempotently()) {
            // the provider may have captured and a retry could capture again: the hold stays `capturing` (no retry
            // can claim it) until someone checks the payment with the provider
            report(new \RuntimeException('Card hold capture outcome unknown for transaction '.$transaction->id.': check it with the provider.'));
            log_activity('payment.capture_unknown', $transaction, ['transaction_id' => $transaction->id, 'gateway' => (string) $transaction->transaction_gatway, 'amount_minor' => $minor], (int) $transaction->store_id);

            return ['ok' => false, 'reference' => null, 'message' => 'The capture result is unknown. Check the payment with the provider before trying again.'];
        }

        if (! $result['ok']) {
            DB::transaction(function () use ($transaction, $unknown) {
                $open = Transaction::query()->whereKey($transaction->getKey())->where('status', self::STATUS_CAPTURING)->lockForUpdate()->first();
                if ($open !== null) {
                    $fill = ['status' => self::STATUS_AUTHORISED];
                    if (! $unknown) {
                        $details = (array) ($open->details ?? []);
                        unset($details['capture_pending']); // a refused capture: the next one is a new request
                        $fill['details'] = $details;
                    }
                    $open->forceFill($fill)->save();
                }
            });
            $transaction->refresh();

            return $result;
        }
        $this->recordCaptured($transaction, $minor, (string) ($result['reference'] ?? ''), []);

        return $result + ['captured_minor' => $minor];
    }

    /**
     * grocery-nits2 (REVIEW, Opus: money): an admin settles a capture left `capturing` after an unknown outcome on a
     * gateway whose captures cannot be retried safely, once they checked the payment with the provider:
     *  - `$captured`: the provider took the stored request's amount (`capture_pending.minor`); it is recorded and
     *    settled exactly like a capture that answered;
     *  - not captured: the hold is `authorised` again and the stored key is dropped, so the next capture is a new
     *    request.
     * Refused unless the transaction is still `capturing` (claimed under its row lock, so it is resolved once).
     *
     * @param  array<string, mixed>  $audit  who checked and when, kept on the transaction
     * @return array{ok: bool, message: string|null, captured_minor: int}
     */
    public function resolveCapture(Transaction $transaction, bool $captured, ?string $reference, array $audit = []): array
    {
        $minor = (int) data_get($transaction->details, 'capture_pending.minor', 0);
        if ((string) $transaction->status !== self::STATUS_CAPTURING) {
            return ['ok' => false, 'message' => 'This payment is not waiting for a capture check.', 'captured_minor' => 0];
        }
        if ($captured && ($minor <= 0 || $minor > (int) $transaction->amount_minor)) {
            return ['ok' => false, 'message' => 'No capture amount is recorded for this payment.', 'captured_minor' => 0];
        }
        if ($captured) {
            $done = $this->recordCaptured($transaction, $minor, (string) ($reference ?? ''), ['capture_resolved' => $audit + ['captured' => true]]);

            return $done
                ? ['ok' => true, 'message' => null, 'captured_minor' => $minor]
                : ['ok' => false, 'message' => 'This payment is not waiting for a capture check.', 'captured_minor' => 0];
        }
        $reset = DB::transaction(function () use ($transaction, $audit) {
            $open = Transaction::query()->whereKey($transaction->getKey())->where('status', self::STATUS_CAPTURING)->lockForUpdate()->first();
            if ($open === null) {
                return false;
            }
            $details = (array) ($open->details ?? []);
            unset($details['capture_pending']);
            $details['capture_resolved'] = $audit + ['captured' => false];
            $open->forceFill(['status' => self::STATUS_AUTHORISED, 'details' => $details])->save();

            return true;
        });
        $transaction->refresh();

        return $reset
            ? ['ok' => true, 'message' => null, 'captured_minor' => 0]
            : ['ok' => false, 'message' => 'This payment is not waiting for a capture check.', 'captured_minor' => 0];
    }

    /**
     * The provider captured `$minor`: the `capturing` transaction (re-read under its row lock) carries the captured
     * amount, its authorised amount in `details.authorised_minor`, and goes back to pending for the normal settlement
     * (Settlement::settle → paySuccess), once. Returns false when it is no longer `capturing`.
     *
     * @param  array<string, mixed>  $extraDetails
     */
    private function recordCaptured(Transaction $transaction, int $minor, string $reference, array $extraDetails): bool
    {
        $currency = Settlement::currency($transaction, 'USD');
        $recorded = DB::transaction(function () use ($transaction, $minor, $reference, $extraDetails, $currency) {
            $open = Transaction::query()->whereKey($transaction->getKey())->where('status', self::STATUS_CAPTURING)->lockForUpdate()->first();
            if ($open === null) {
                return false;
            }
            $details = (array) ($open->details ?? []);
            $details['authorised_minor'] = (int) $open->amount_minor;
            $details['capture_reference'] = $reference;
            $details['capture_idempotency_key'] = data_get($details, 'capture_pending.key');
            unset($details['capture_pending']);
            $open->forceFill([
                'amount' => Money::majorFromMinor($minor, $currency),
                'base_amount' => Money::majorFromMinor($minor, $currency),
                'details' => array_merge($details, $extraDetails),
                'status' => null, // back to pending: the normal settlement credits the captured amount once
            ])->save();

            return true;
        });
        $transaction->refresh();
        if ($recorded) {
            // the captured amount settles like any confirmed order payment (outbox credit → paySuccess), once
            try {
                Settlement::settle($transaction, (string) $transaction->transaction_gatway, null, claim: true);
            } catch (\Throwable $e) {
                PaymentLog::settlementFailed((string) $transaction->transaction_gatway, $e); // the relay retries the credit
            }
            $transaction->refresh();
        }

        return $recorded;
    }

    /**
     * Release an authorised hold.
     *
     * @return array{ok: bool, reference: string|null, message: string|null}
     */
    public function void(Transaction $transaction): array
    {
        if ((string) $transaction->status !== self::STATUS_AUTHORISED) {
            return ['ok' => false, 'reference' => null, 'message' => 'This card hold is not open.'];
        }
        try {
            $result = (string) $transaction->transaction_gatway === 'stub'
                ? ['ok' => true, 'reference' => 'stub-void-'.$transaction->getKey(), 'message' => null]
                : $this->gateway($transaction)->void($transaction);
        } catch (\Throwable $e) {
            PaymentLog::failure((string) $transaction->transaction_gatway, $e, ['transaction_id' => $transaction->id, 'step' => 'void']);
            $result = ['ok' => false, 'reference' => null, 'message' => $e->getMessage()];
        }
        if ($result['ok']) {
            Transaction::query()->whereKey($transaction->getKey())->where('status', self::STATUS_AUTHORISED)->update(['status' => self::STATUS_VOIDED]);
            $transaction->refresh();
        }

        return $result;
    }

    private function gateway(Transaction $transaction): AuthorisesPayments
    {
        $adapter = $this->adapterFor((string) $transaction->transaction_gatway);
        if (! $adapter instanceof AuthorisesPayments) {
            throw new \RuntimeException('This gateway cannot capture a card hold.');
        }

        return $adapter;
    }

    private function adapterFor(string $folder): ?GatewayAdapter
    {
        if ($folder === '' || preg_match('/^[a-z0-9_]+$/', $folder) !== 1) {
            return null;
        }
        $controller = PaymentController::controllerFor($folder);
        if (! class_exists($controller) || ! is_subclass_of($controller, GatewayAdapterController::class)) {
            return null;
        }

        return $controller::adapter();
    }
}
