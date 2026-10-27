<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Listeners;

use App\Models\Order;
use App\Services\PosService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Contracts\TerminalPayments;
use Juwri\Modules\Payments\Events\TerminalPaymentCaptured;

/**
 * P5-26: a captured SoftPOS charge marks its POS order paid (card) exactly once: the order row is locked, the payment's
 * one-time settlement claim is taken in the same transaction, and PosService::settle runs the usual paid side effects
 * (shift, loyalty, GL; the e-invoice from the order's paid hook). A failure rolls the claim back, so the next reconcile
 * of the payment (status read, callback, resend) settles it. An order already paid another way is not paid twice: the
 * claim is kept and the double payment is logged for the manager to refund.
 */
final class SettleTerminalPayment
{
    public function __construct(private readonly TerminalPayments $terminal) {}

    public function handle(TerminalPaymentCaptured $e): void
    {
        $store = Store::query()->find($e->storeId);
        if ($store === null) {
            return;
        }
        DB::transaction(function () use ($e, $store) {
            $order = Order::query()->where('id', $e->orderId)->where('store_id', $e->storeId)->lockForUpdate()->first();
            if ($order === null || ! $this->terminal->claimSettlement($store, $e->paymentId)) {
                return;
            }
            if ($order->is_paid || $order->status === 'cancelled') {
                Log::warning("[softpos] card payment {$e->paymentId} captured for order {$order->id} that is already paid or cancelled: refund it");

                return;
            }
            PosService::settle($order, 'card', null, $e->tipMinor === null ? null : Money::majorFromMinor($e->tipMinor, $e->currency));
        });
    }
}
