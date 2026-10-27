<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services;

use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Payments\Contracts\OrderPaymentHandler;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P1-08d: Ordering's side of the Payments order-payment hand-off — the existing settle path, unchanged.
 */
final class OrderPaymentSettler implements OrderPaymentHandler
{
    public function paid(Transaction $transaction): mixed
    {
        return PaymentController::paySuccess($transaction);
    }

    public function buyerPage(Transaction $transaction): string
    {
        return PaymentController::handOffScript($transaction, Order::find($transaction->product_id), Store::find($transaction->store_id));
    }

    /** P3-09: a weighed basket's card hold was authorised — the order goes ahead for picking. */
    public function authorised(Transaction $transaction): void
    {
        app(\Juwri\Modules\Ordering\Services\Payments\CardHoldOrders::class)->authorised($transaction);
    }
}
