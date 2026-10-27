<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Services\Inventory;

use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\SupplierInvoice;

/**
 * SYSTEMS-RUN SUP-P1: supplier invoice capture. Manual entry + OCR extraction
 * via the platform AI layer. The extractor is injectable so tests bind a Fake
 * (no live vision call). Produces a 3-way reconcile view (PO vs received vs
 * invoice) with mismatch flags.
 *
 * Bug 6 (2026-10-01): totals round to the currency's exponent via Money::round (was round(…, 2): a 7.125 OMR invoice
 * was stored as 7.130); the reconcile tolerance is one minor unit of that currency (was a fixed 0.01).
 */
class InvoiceCaptureService
{
    /** @var callable|null fn(string): array<int,array{name,qty,unit_cost,total}> */
    private $extractor;

    public function __construct(?callable $extractor = null)
    {
        $this->extractor = $extractor;
    }

    public function capture(int $storeId, array $data): SupplierInvoice
    {
        $lines = $data['lines'] ?? [];
        $total = Money::round((float) ($data['total'] ?? array_sum(array_column($lines, 'total'))), Currencies::exponent(store_currency($storeId)));

        return SupplierInvoice::create([
            'store_id' => $storeId,
            'supplier_id' => $data['supplier_id'] ?? null,
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'invoice_no' => $data['invoice_no'] ?? null,
            'total' => $total,
            'lines' => array_values($lines),
            'attachment' => $data['attachment'] ?? null,
            'source' => $data['source'] ?? 'manual',
        ]);
    }

    /** OCR an uploaded invoice into structured lines (Fake-backed in tests). */
    public function ocr(string $path): array
    {
        if ($this->extractor) {
            return ($this->extractor)($path);
        }

        // Default seam: no live vision provider configured → empty (owner corrects manually).
        return [];
    }

    /** 3-way reconcile: PO vs received vs invoice line totals. */
    public function reconcile(PurchaseOrder $po, SupplierInvoice $invoice): array
    {
        $exponent = Currencies::exponent($po->minorMoneyCurrency());
        $ordered = Money::round((float) $po->items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_cost), $exponent);
        $received = Money::round((float) $po->items->sum(fn ($i) => (float) $i->qty_received * (float) ($i->actual_unit_cost ?? $i->unit_cost)), $exponent);
        $invoiced = Money::round((float) $invoice->total, $exponent);
        // One minor unit of the currency (USD 0.01 as before, KWD 0.001, JPY 1), compared on the rounded values
        // with a half-unit margin so a float representation error never decides it.
        $unit = 10 ** -$exponent;
        $tolerance = $unit * 1.5;

        return [
            'ordered' => $ordered,
            'received' => $received,
            'invoiced' => $invoiced,
            'mismatch' => abs($received - $invoiced) > $tolerance || abs($ordered - $invoiced) > $tolerance,
        ];
    }
}
