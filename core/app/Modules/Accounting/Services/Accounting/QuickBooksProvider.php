<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting;

use App\Models\Order;
use Illuminate\Http\Client\Response;

/**
 * GAPS-RUN-2 B3-P1: QuickBooks driver — behavior-identical to the original inline
 * SyncInvoiceJob call (POST {base_url}/invoices with the generic invoice payload).
 *
 * Bug 6 (2026-10-01): amounts go out at the ORDER currency's decimals (KWD 3, JPY 0) — the generic payload has no
 * provider-side decimal cap (QBO's own Amount type is a 10.5 decimal). Was a fixed round(…, 2) in the store's
 * CURRENT currency.
 *
 * Bug (2026-10-01, export ≠ order): the payload mirrors the STORED order ({@see OrderInvoice}) — every line at the
 * price sold (unit-price + modifier + option snapshots, never the live menu), delivery / tray charge, service charge,
 * tip, donation and discount as typed lines, tax as an explicit `tax` line plus `tax_total` (exclusive), and
 * Σ lines.amount = total = the order's stored grand total, at the currency's decimals.
 */
class QuickBooksProvider implements AccountingProvider
{
    public function key(): string
    {
        return 'quickbooks';
    }

    public function endpoint(array $conn): string
    {
        return rtrim($conn['base_url'], '/').'/invoices';
    }

    public function headers(array $conn): array
    {
        return [];
    }

    public function buildPayload(Order $order): array
    {
        $invoice = OrderInvoice::fromOrder($order);

        $lines = array_map(fn (array $l) => [
            'type' => $l['kind'],
            'description' => $l['description'],
            'quantity' => $l['quantity'],
            'unit_amount' => $invoice->major($l['unit_minor']),
            'amount' => $invoice->major($l['amount_minor']),
            'taxable' => $l['taxable'],
        ], $invoice->lines);
        if ($invoice->taxMinor !== 0) {
            $lines[] = [
                'type' => 'tax', 'description' => 'Tax', 'quantity' => 1,
                'unit_amount' => $invoice->major($invoice->taxMinor), 'amount' => $invoice->major($invoice->taxMinor), 'taxable' => false,
            ];
        }

        return [
            'reference' => 'ORDER-'.$order->id,
            'currency' => $invoice->currency,
            'tax_mode' => 'exclusive',
            'subtotal' => $invoice->major($invoice->subtotalMinor),
            'discount_total' => $invoice->major($invoice->discountMinor),
            'tax_total' => $invoice->major($invoice->taxMinor),
            'total' => $invoice->major($invoice->totalMinor),
            'lines' => $lines,
        ];
    }

    public function extractId(Response $response): string
    {
        return (string) ($response->json('id') ?? $response->json('Id') ?? '');
    }
}
