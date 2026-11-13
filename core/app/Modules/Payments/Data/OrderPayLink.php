<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Data;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P6-09 V-15: the order pay page link, for other modules (a public part, so Ordering / Storefront / Agents need not
 * reach into Payments' Services). A phone-agent order's pay page is reachable only through a temporary signed URL
 * (`payment.phone`); every other order uses the plain `payment.index` URL.
 */
final class OrderPayLink
{
    /** Whether the transaction pays a phone-agent order (its pay page needs the signed link). */
    public static function isPhoneOrder(Transaction $transaction): bool
    {
        return $transaction->transaction_method === 'order'
            && Order::whereKey((int) $transaction->product_id)->value('source') === 'phone';
    }

    /** The order pay page URL — signed until the order's payment hold ends for a phone-agent order, plain otherwise. */
    public static function url(Transaction $transaction): string
    {
        if (! self::isPhoneOrder($transaction)) {
            return route('payment.index', $transaction);
        }
        $expires = Order::whereKey((int) $transaction->product_id)->value('payment_expires_at');
        $expires = $expires ? Carbon::parse($expires) : now()->addMinutes(30);

        return URL::temporarySignedRoute('payment.phone', $expires, ['transaction' => $transaction->getKey()]);
    }
}
