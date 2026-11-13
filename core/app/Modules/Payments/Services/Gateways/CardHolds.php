<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Support\Facades\DB;
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
     * Capture `$minor` (transaction currency) of an authorised hold.
     *
     * @return array{ok: bool, reference: string|null, message: string|null}
     */
    public function capture(Transaction $transaction, int $minor): array
    {
        $currency = Settlement::currency($transaction, 'USD');
        $authorised = (int) $transaction->amount_minor;
        if ($minor <= 0 || $minor > $authorised) {
            return ['ok' => false, 'reference' => null, 'message' => 'The capture must be more than 0 and not above the authorised amount.'];
        }
        $claimed = Transaction::query()->whereKey($transaction->getKey())->where('status', self::STATUS_AUTHORISED)
            ->update(['status' => self::STATUS_CAPTURING]) === 1;
        if (! $claimed) {
            return ['ok' => false, 'reference' => null, 'message' => 'This card hold is not open for capture.'];
        }
        $transaction->refresh();

        try {
            $result = (string) $transaction->transaction_gatway === 'stub'
                ? ['ok' => true, 'reference' => 'stub-capture-'.$transaction->getKey(), 'message' => null]
                : $this->gateway($transaction)->capture($transaction, Money::ofMinor($minor, $currency));
        } catch (\Throwable $e) {
            PaymentLog::failure((string) $transaction->transaction_gatway, $e, ['transaction_id' => $transaction->id, 'step' => 'capture']);
            $result = ['ok' => false, 'reference' => null, 'message' => $e->getMessage()];
        }

        DB::transaction(function () use ($transaction, $result, $minor, $authorised, $currency) {
            if (! $result['ok']) {
                Transaction::query()->whereKey($transaction->getKey())->where('status', self::STATUS_CAPTURING)->update(['status' => self::STATUS_AUTHORISED]);

                return;
            }
            $details = (array) ($transaction->details ?? []);
            $details['authorised_minor'] = $authorised;
            $details['capture_reference'] = $result['reference'];
            $transaction->forceFill([
                'amount' => Money::majorFromMinor($minor, $currency),
                'base_amount' => Money::majorFromMinor($minor, $currency),
                'details' => $details,
                'status' => null, // back to pending: the normal settlement credits the captured amount once
            ])->save();
        });
        $transaction->refresh();
        if ($result['ok']) {
            // the captured amount settles like any confirmed order payment (outbox credit → paySuccess), once
            try {
                Settlement::settle($transaction, (string) $transaction->transaction_gatway, null, claim: true);
            } catch (\Throwable $e) {
                PaymentLog::settlementFailed((string) $transaction->transaction_gatway, $e); // the relay retries the credit
            }
            $transaction->refresh();
        }

        return $result;
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
