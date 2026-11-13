<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Modules\Payments\Contracts\SettlementHandlerMissing;
use Juwri\Modules\Payments\Contracts\TransactionFulfiller;
use Juwri\Modules\Payments\Models\BillingReceipt;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P1-13 13g: the Community edition's fulfilment of a settled Transaction — Billing (ee) binds its own (the platform
 * checkout, which settles store payments through {@see StorePayments} too). A business's own customer payments (diner
 * store credit, gift cards, reservation / catering deposits) are credited exactly as Billing does: once, claimed through
 * `credited_at`, with the durable receipt. Anything a platform purchase buys (a plan, add-on, app, platform-wallet
 * top-up, reseller or marketplace payment) is NOT settled without its ee module: nothing is claimed or receipted, a
 * platform alert names the module, and the exception keeps the credit message pending for a retry (or a manual refund).
 */
final class StoreTransactionFulfiller implements TransactionFulfiller
{
    public function fulfil(Transaction $transaction): mixed
    {
        if (! StorePayments::handles($transaction)) {
            $module = StorePayments::EE_MODULES[$transaction->transaction_method] ?? 'Billing';
            self::refuse(($transaction->transaction_method ?: 'payment').' transaction #'.$transaction->id.' not credited',
                'Payment #'.$transaction->id.' left unsettled: no fulfiller for '.$transaction->transaction_method, $module);
        }

        // Once-only settlement guard (same as Billing's): only the caller that flips credited_at credits.
        if (! empty($transaction->id)) {
            $claimed = Transaction::where('id', $transaction->id)
                ->whereNull('credited_at')
                ->update(['credited_at' => now()]);
            if ($claimed !== 1) {
                return null; // already credited by a concurrent/replayed callback
            }
            $transaction->credited_at = now();

            BillingReceipt::recordFromTransaction($transaction);
        }

        StorePayments::settle($transaction);

        return null;
    }

    public function webhook(string $gateway, mixed $metadata, mixed $paymentId, mixed $subscriptionId): mixed
    {
        // A platform plan subscription's gateway notification: only Billing (ee) knows what it buys.
        self::refuse('subscription payment '.$gateway.' '.(is_scalar($paymentId) ? (string) $paymentId : '').' not credited',
            'Subscription payment ('.$gateway.') left unsettled: no fulfiller', 'Billing');
    }

    private static function refuse(string $what, string $message, string $module): never
    {
        $alert = static fn () => AlertDispatcher::send('payment', $what.': the '.$module.' module is not active (left unsettled for retry or a manual refund)');
        $alert();
        // The exception rolls back the caller's DB transaction and the alert row with it: raise it again once rolled
        // back (AlertDispatcher de-dupes per day).
        if (DB::transactionLevel() > 0) {
            DB::afterRollBack($alert);
        }

        throw new SettlementHandlerMissing($message, $module);
    }
}
