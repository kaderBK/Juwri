<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting;

use App\Models\Order;
use Brick\Math\RoundingMode;
use Illuminate\Http\Client\Response;
use Juwri\Core\Money\Money;

/**
 * GAPS-RUN-2 B3-P1: Xero driver. Invoices API is POST {base_url}/Invoices with an
 * ACCREC invoice (Contact + LineItems) and a required Xero-tenant-id header. Base
 * url is stored per connection (e.g. https://api.xero.com/api.xro/2.0).
 *
 * Bug 6 (2026-10-01): amounts follow the ORDER's currency. Xero accepts UnitAmount with up to 4 decimals when the
 * request carries `unitdp=4`, so the unit price goes out at the currency's decimals (capped at 4); Xero computes
 * LineAmount and the invoice total itself at 2 decimals — its own cap, which a 3-decimal currency (KWD/BHD/OMR/JOD)
 * still meets on Xero's side. Was a fixed round(…, 2) in the store's CURRENT currency.
 *
 * Bug (2026-10-01, export ≠ order): the invoice mirrors the STORED order ({@see OrderInvoice}) — lines at the price
 * sold (unit-price + modifier + option snapshots, never the live menu), delivery / tray charge, service charge, tip,
 * donation and discount as their own lines, the stored tax as each item line's TaxAmount (LineAmountTypes
 * Exclusive), and a rounding line when Xero's 2-decimal arithmetic would land off the order's total.
 */
class XeroProvider implements AccountingProvider
{
    /** Default sales account; owner can remap later. */
    public const ACCOUNT_CODE = '200';

    /** Xero's default output-tax type (the supplied TaxAmount, not this type's rate, sets the tax). */
    public const TAX_TYPE = 'OUTPUT';

    /** Lines juwri does not tax (delivery, tip, service charge, donation, discount, rounding). */
    public const NO_TAX = 'NONE';

    public function key(): string
    {
        return 'xero';
    }

    public function endpoint(array $conn): string
    {
        return rtrim($conn['base_url'], '/').'/Invoices?unitdp=4';
    }

    public function headers(array $conn): array
    {
        // Xero requires the tenant id on every call (multi-tenant connections).
        return ['Xero-tenant-id' => (string) ($conn['tenant_id'] ?? '')];
    }

    public function buildPayload(Order $order): array
    {
        $invoice = OrderInvoice::fromOrder($order);
        $dp = min(4, $invoice->exponent()); // UnitAmount precision (request carries unitdp=4)

        // Exclusive: juwri adds tax on top of the item prices. The stored tax is spread over the taxable (item)
        // lines as each line's TaxAmount — Xero keeps a supplied TaxAmount instead of recomputing it from TaxType,
        // so Xero's tax equals juwri's (at Xero's 2 decimals).
        $taxCents = self::cents($invoice->major($invoice->taxMinor));
        $taxable = array_keys(array_filter($invoice->lines, static fn (array $l) => $l['taxable']));
        $taxShares = [];
        if ($taxCents !== 0 && $taxable !== []) {
            $weights = array_map(static fn (int $i) => max(0, $invoice->lines[$i]['amount_minor']), $taxable);
            if (array_sum($weights) === 0) {
                $weights = array_fill(0, count($taxable), 1);
            }
            foreach (Money::ofMinor($taxCents, 'USD')->allocate($weights) as $k => $share) {
                $taxShares[$taxable[$k]] = $share->minor();
            }
        }

        $lineItems = [];
        $xeroCents = 0; // what Xero will compute: Σ round2(Quantity × UnitAmount) + Σ TaxAmount
        foreach ($invoice->lines as $i => $line) {
            $unit = Money::decimal(Money::ofMinor($line['unit_minor'], $invoice->currency)->major());
            $item = [
                'Description' => $line['description'],
                'Quantity' => $line['quantity'],
                'UnitAmount' => $unit->toScale($dp, RoundingMode::HalfUp)->toFloat(),
                'AccountCode' => self::ACCOUNT_CODE,
                'TaxType' => isset($taxShares[$i]) ? self::TAX_TYPE : self::NO_TAX,
            ];
            if (isset($taxShares[$i])) {
                $item['TaxAmount'] = (float) ($taxShares[$i] / 100);
                $xeroCents += $taxShares[$i];
            }
            $xeroCents += self::cents((string) $unit->multipliedBy($line['quantity']));
            $lineItems[] = $item;
        }
        if ($taxCents !== 0 && $taxable === []) {
            // Tax with no item line to carry it (never on a consistent order) still reaches the invoice total.
            $lineItems[] = ['Description' => 'Tax', 'Quantity' => 1, 'UnitAmount' => (float) ($taxCents / 100), 'AccountCode' => self::ACCOUNT_CODE, 'TaxType' => self::NO_TAX];
            $xeroCents += $taxCents;
        }

        // Xero keeps LineAmount / totals at 2 decimals. If its rounding lands off juwri's total (3-decimal currencies
        // only), a rounding line brings the Xero total to juwri's total at Xero's precision.
        $targetCents = self::cents($invoice->major($invoice->totalMinor));
        if ($targetCents !== $xeroCents) {
            $lineItems[] = [
                'Description' => 'Rounding (Xero 2-decimal amounts; order total '.$invoice->currency.' '
                    .Money::ofMinor($invoice->totalMinor, $invoice->currency)->major().')',
                'Quantity' => 1,
                'UnitAmount' => (float) (($targetCents - $xeroCents) / 100),
                'AccountCode' => self::ACCOUNT_CODE,
                'TaxType' => self::NO_TAX,
            ];
        }

        return [
            'Type' => 'ACCREC',
            'Contact' => ['Name' => 'Online customer'],
            'Reference' => 'ORDER-'.$order->id,
            'CurrencyCode' => $invoice->currency,
            'Status' => 'AUTHORISED',
            'LineAmountTypes' => 'Exclusive',
            'LineItems' => $lineItems,
        ];
    }

    /** A major amount in hundredths, HALF_UP (Xero's 2-decimal amounts). */
    private static function cents(float|string $major): int
    {
        return Money::decimal($major)->toScale(2, RoundingMode::HalfUp)->getUnscaledValue()->toInt();
    }

    public function extractId(Response $response): string
    {
        return (string) ($response->json('Invoices.0.InvoiceID') ?? $response->json('InvoiceID') ?? '');
    }
}
