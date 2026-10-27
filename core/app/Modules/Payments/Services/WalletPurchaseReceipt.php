<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Contracts\PurchaseReceipts;
use Juwri\Modules\Payments\Mail\WalletPurchaseReceiptMail;
use Juwri\Modules\Payments\Models\BillingReceipt;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P6-252: a purchase paid from the platform wallet (theme licence, app charge) gets the same durable receipt as a card
 * charge (billing_receipts) and a receipt e-mail to the buyer. Idempotent: a retry of the same purchase finds the
 * existing receipt and sends nothing again. The mail is queued after commit, so a rolled-back purchase never mails.
 */
final class WalletPurchaseReceipt implements PurchaseReceipts
{
    public function issueFor(Transaction $transaction): BillingReceipt
    {
        return self::issue($transaction);
    }

    public static function issue(Transaction $transaction): BillingReceipt
    {
        $existing = BillingReceipt::query()->where('transaction_id', $transaction->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $receipt = BillingReceipt::recordFromTransaction($transaction);
        if (! $receipt->wasRecentlyCreated) {
            return $receipt; // a concurrent retry recorded it first
        }

        $user = User::query()->find($transaction->user_id);
        if ($user !== null && ! empty($user->email)) {
            $url = Route::has('invoice') ? route('invoice', $transaction) : null;
            $mail = new WalletPurchaseReceiptMail(
                (string) $transaction->product_name,
                (string) $transaction->amount,
                (string) ($transaction->currency_code ?: platform_currency()),
                (int) $receipt->id,
                $url,
            );
            DB::afterCommit(static fn () => Mail::to($user->email)->queue($mail));
        }

        return $receipt;
    }
}
