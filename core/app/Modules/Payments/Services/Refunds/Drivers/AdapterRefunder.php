<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Refunds\Drivers;

use App\Models\Order;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\GatewayAdapter;
use Juwri\Modules\Payments\Services\Refunds\GatewayRefunder;

/**
 * Order refunds through a payment adapter's own refund() (WI-06): finds the
 * order's settled payment on that gateway and hands it to the adapter.
 * Registered for the gateways listed in config('refunds.adapters').
 */
final class AdapterRefunder implements GatewayRefunder
{
    public function __construct(private readonly GatewayAdapter $adapter) {}

    public function gateway(): string
    {
        return $this->adapter->key();
    }

    public function refund(Order $order, float $amount): array
    {
        $transaction = Transaction::query()
            ->where('transaction_method', 'order')
            ->where('product_id', $order->id)
            ->where('transaction_gatway', $this->adapter->key())
            ->whereIn('status', [Transaction::STATUS_SUCCESS, Transaction::STATUS_CONSUMED])
            ->latest('id')
            ->first();

        if ($transaction === null) {
            return ['ok' => false, 'reference' => null, 'message' => ___('Could not refund automatically — complete this refund in your payment provider.')];
        }

        // Bug batch 6: $amount is in the order's currency; the adapter (RefundGuard) refuses it when that is not the
        // currency the payment was made in — never re-read in another currency.
        $orderCurrency = strtoupper(trim((string) ($order->currency_code ?: store_options($order->store_id, 'currency_code'))));

        return $this->adapter->refund($transaction, $amount, $orderCurrency !== '' ? $orderCurrency : null);
    }
}
