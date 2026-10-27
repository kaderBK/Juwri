<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Services\Inventory;

use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Modules\Inventory\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;

/**
 * HPMS E7: what a store owes a supplier and the account statement.
 *
 * The "charge" side is the VALUE OF GOODS ACTUALLY RECEIVED from the supplier —
 * summed as qty_received × (actual or ordered) unit cost across their purchase
 * orders, exactly the basis CogsService uses. Because it keys on qty_received it
 * reflects partial deliveries automatically. The "credit" side is the recorded
 * supplier_payments. balance = charges − payments.
 *
 * Bug 6 (2026-10-01): every total rounds to the store currency's exponent through Money::round (HALF_UP, PHP-version
 * independent) — was a fixed round(…, 2), which cut 3-decimal currencies (KWD/BHD/OMR/JOD…) to 2 decimals.
 */
class SupplierBalanceService
{
    /** The supplier's store currency exponent (its POs and payments are stamped in it). */
    private static function exponent(int|string|null $storeId): int
    {
        return $storeId ? Currencies::exponent(store_currency($storeId)) : Currencies::DEFAULT_EXPONENT;
    }

    /** Value of goods received from this supplier (reflects partial receiving). */
    public static function chargesTotal(Supplier $supplier): float
    {
        $poIds = PurchaseOrder::where('supplier_id', $supplier->id)->pluck('id');
        if ($poIds->isEmpty()) {
            return 0.0;
        }

        $total = 0.0;
        foreach (PurchaseOrderItem::whereIn('purchase_order_id', $poIds)->get() as $item) {
            $total += (float) ($item->qty_received ?? 0) * (float) ($item->actual_unit_cost ?? $item->unit_cost ?? 0);
        }

        return Money::round($total, self::exponent($supplier->store_id));
    }

    /** Total recorded payments to this supplier. */
    public static function paymentsTotal(Supplier $supplier): float
    {
        return Money::round((float) SupplierPayment::where('supplier_id', $supplier->id)->sum(DB::raw(SupplierPayment::minorMoneySql('amount'))), self::exponent($supplier->store_id));
    }

    /** Outstanding balance owed to the supplier (charges − payments). */
    public static function balance(Supplier $supplier): float
    {
        return Money::round(self::chargesTotal($supplier) - self::paymentsTotal($supplier), self::exponent($supplier->store_id));
    }

    /** Received value of one purchase order (qty_received × cost). */
    public static function poReceivedValue(PurchaseOrder $po): float
    {
        $v = 0.0;
        foreach (PurchaseOrderItem::where('purchase_order_id', $po->id)->get() as $item) {
            $v += (float) ($item->qty_received ?? 0) * (float) ($item->actual_unit_cost ?? $item->unit_cost ?? 0);
        }

        return Money::round($v, Currencies::exponent($po->minorMoneyCurrency()));
    }

    /**
     * The interleaved statement: received purchase orders (charges) and payments
     * (credits), newest first.
     *
     * @return array<int,array{type:string,date:?string,label:string,amount:float}>
     */
    public static function statement(Supplier $supplier): array
    {
        $rows = [];

        PurchaseOrder::where('supplier_id', $supplier->id)->get()
            ->each(function ($po) use (&$rows) {
                $value = self::poReceivedValue($po);
                if ($value > 0) {
                    $rows[] = [
                        'type'   => 'charge',
                        'date'   => optional($po->received_at ?? $po->created_at)->toDateTimeString(),
                        'label'  => 'PO #'.$po->id,
                        'amount' => $value,
                    ];
                }
            });

        SupplierPayment::where('supplier_id', $supplier->id)->orderByDesc('id')->get()
            ->each(function ($p) use (&$rows) {
                $rows[] = [
                    'type'   => 'payment',
                    'date'   => optional($p->paid_at ?? $p->created_at)->toDateTimeString(),
                    'label'  => trim(($p->method ? $p->method : 'Payment').($p->note ? ' — '.$p->note : '')),
                    'amount' => -1 * (float) $p->amount,
                ];
            });

        usort($rows, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

        return $rows;
    }
}
