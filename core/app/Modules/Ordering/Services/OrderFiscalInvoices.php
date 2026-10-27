<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Contracts\FiscalInvoices;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Payments\Contracts\Outbox;

/**
 * P4-01: the e-invoice of a paid order and the credit note of each refund (ZATCA). Before, nothing issued them: an
 * onboarded store reported no invoice at all, its receipt QR stayed self-generated, and a refund left no credit note.
 *
 * Every settle path writes `is_paid` through Eloquent and every refund / void writes an {@see OrderAdjustment} row,
 * so the two model hooks are the choke points. The issuing runs as a transactional-outbox message after the
 * settling transaction commits (a rolled-back payment issues nothing; a failing authority call is retried by the
 * relay and can never fail a payment). Idempotent twice over: the outbox dedupe key, and Fiscal's one document per
 * store + source reference (`order:<id>` / `refund:<adjustment id>`).
 */
final class OrderFiscalInvoices
{
    public const INVOICE = 'fiscal.order_paid.invoice';

    public const CREDIT = 'fiscal.order_refund.credit_note';

    public static function paid(Order $order): void
    {
        if (! app(FiscalInvoices::class)->issuesFor((int) $order->store_id)) {
            return;
        }
        $outbox = app(Outbox::class);
        $id = $outbox->record(self::INVOICE, ['order_id' => (int) $order->id], self::INVOICE.':'.$order->id);
        $outbox->relayAfterCommit($id === null ? [] : [$id]);
    }

    public static function adjusted(OrderAdjustment $adjustment): void
    {
        if (! in_array((string) $adjustment->type, ['refund', 'void'], true) || (float) $adjustment->amount <= 0) {
            return;
        }
        $storeId = (int) DB::table('orders')->where('id', $adjustment->order_id)->value('store_id');
        if (! app(FiscalInvoices::class)->issuesFor($storeId)) {
            return;
        }
        $outbox = app(Outbox::class);
        $id = $outbox->record(self::CREDIT, ['adjustment_id' => (int) $adjustment->id], self::CREDIT.':'.$adjustment->id);
        $outbox->relayAfterCommit($id === null ? [] : [$id]);
    }

    public static function register(Outbox $outbox): void
    {
        $outbox->handle(self::INVOICE, function (array $p): void {
            // Recorded only on a paid transition: the sale happened even when the order is already refunded and unpaid
            // again by the time the relay runs (before: skipped — no invoice, and so no credit note either).
            $order = Order::find((int) ($p['order_id'] ?? 0));
            if ($order && ($store = Store::find((int) $order->store_id))) {
                app(FiscalInvoices::class)->invoice($store, OrderFiscalContext::for($store, $order), 'order:'.$order->id,
                    $order->branch_id ? (int) $order->branch_id : null);
            }
        });
        $outbox->handle(self::CREDIT, function (array $p): void {
            $adjustment = OrderAdjustment::find((int) ($p['adjustment_id'] ?? 0));
            $order = $adjustment ? Order::find((int) $adjustment->order_id) : null;
            $store = $order ? Store::find((int) $order->store_id) : null;
            if (! $adjustment || ! $order || ! $store) {
                return;
            }
            $fiscal = app(FiscalInvoices::class);
            $branchId = $order->branch_id ? (int) $order->branch_id : null;
            // P4-01: a refund made while the sale's invoice is still owed (its outbox message pending, failed or not yet
            // relayed) issues that invoice first — same source reference, so the pending message then finds it done.
            // A refund means the order was paid; a void only when the order is paid now (an unpaid void owes nothing).
            if (! $fiscal->hasInvoice($store, (int) $order->id) && ((string) $adjustment->type === 'refund' || $order->is_paid)) {
                $fiscal->invoice($store, OrderFiscalContext::for($store, $order), 'order:'.$order->id, $branchId);
            }
            $fiscal->creditNote($store, self::refundContext($store, $order, $adjustment), 'refund:'.$adjustment->id,
                trim((string) $adjustment->reason) !== '' ? (string) $adjustment->reason : ((string) $adjustment->type === 'void' ? 'Void' : 'Refund'),
                $branchId);
        });
    }

    /**
     * The refunded part of the sale: the refund amount, and its tax pro rata to the order's own tax (a full refund
     * credits exactly the invoiced tax). Never more than the order total.
     */
    public static function refundContext(Store $store, Order $order, OrderAdjustment $adjustment): FiscalReceiptContext
    {
        $sale = OrderFiscalContext::for($store, $order);
        $amount = min((float) $adjustment->amount, $sale->total);
        // P6-258: round in the sale's currency (KWD/BHD/JOD keep 3 decimals).
        $dec = money_decimals((string) ($sale->currency ?: store_currency((int) $store->id)));
        $tax = $amount >= $sale->total ? $sale->taxAmount
            : ($sale->total > 0 ? money_round($amount * $sale->taxAmount / $sale->total, $dec) : 0.0);

        return new FiscalReceiptContext(
            sellerName: $sale->sellerName, vatNumber: $sale->vatNumber, crNumber: $sale->crNumber,
            total: money_round($amount, $dec), taxAmount: $tax, issuedAt: $adjustment->created_at ?? now(),
            currency: $sale->currency, extra: [], orderId: (int) $order->id,
            sellerAddress: $sale->sellerAddress, // P6-176: a note of a standard (B2B) invoice names the seller's address too
        );
    }
}
