<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Jofotara;

use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;

/**
 * TAX-RUN J-P1: builds the JoFotara (Jordan ISTD) invoice. JoFotara models the
 * document as UBL 2.1 which is then encoded to the JSON envelope the ISTD REST
 * API accepts. Supports cash (sales-tax) + receivable variants and credit
 * notes. JOD is a 3-decimal currency; a reconciliation assert protects the
 * one-tax-source invariant. Never recomputes order money.
 */
class JofotaraBuilder
{
    public const INVOICE_CASH = '012';       // sales-tax cash invoice (restaurant default)

    public const INVOICE_RECEIVABLE = '022';  // receivable

    public const CREDIT_NOTE = '381';

    /**
     * @return array{ubl:string,json:array}
     */
    public function build(FiscalReceiptContext $ctx, array $opts = []): array
    {
        $type = $opts['type'] ?? self::INVOICE_CASH;
        $number = $opts['number'] ?? 'INV-1';
        $uuid = $opts['uuid'] ?? '';
        $activityNumber = $opts['activity_number'] ?? '';
        $reference = $opts['reference'] ?? null;

        $this->reconcile(round($ctx->total - $ctx->taxAmount, 3), $ctx->taxAmount, $ctx->total);
        // P6-99: per-line document (JOD, 3 decimals) — lines, discount allowance, VAT per category, rounding.
        $f = FiscalInvoiceMath::compute($ctx, 3);
        $m = fn (int $v): string => FiscalInvoiceMath::fmt($v, 3);

        $ubl = $this->ubl($ctx, $type, $number, $uuid, $f, $reference);

        $json = [
            'invoiceType' => $type,
            'invoiceNumber' => $number,
            'uuid' => $uuid,
            'issueDate' => JordanTime::date($ctx->issuedAt), // Jordan calendar day (see JordanTime)
            'currency' => 'JOD',
            'activityNumber' => $activityNumber,
            'seller' => [
                'name' => $ctx->sellerName,
                'taxNumber' => $ctx->vatNumber,
            ],
            'totals' => [
                'lineExtension' => $m($f['line_extension']),
                'allowanceTotal' => $m($f['allowance_total']),
                'taxExclusive' => $m($f['tax_exclusive']),
                'taxAmount' => $m($f['tax_total']),
                'taxInclusive' => $m($f['tax_inclusive']),
                'payableRounding' => $m($f['rounding']),
                'payable' => $m($f['payable']),
            ],
            'taxSubtotals' => array_map(fn (array $st) => [
                'category' => $st['category'], 'percent' => number_format($st['rate'], 2, '.', ''),
                'taxable' => $m($st['taxable']), 'taxAmount' => $m($st['tax']),
            ], $f['subtotals']),
            'lines' => array_map(fn (array $l) => [
                'description' => $l['name'],
                'quantity' => $l['qty'],
                'unitPrice' => FiscalInvoiceMath::unitPrice($l, 3),
                'discount' => $m($l['discount']),
                'net' => $m($l['net']),
                'taxCategory' => $l['category'],
                'taxPercent' => number_format($l['rate'], 2, '.', ''),
                'taxAmount' => $m($l['tax']),
            ], $f['lines']),
        ];

        if ($reference) {
            $json['reference'] = $reference;
        }

        // ISTD wants the UBL base64-encoded inside the JSON envelope.
        $json['invoice'] = base64_encode($ubl);

        return ['ubl' => $ubl, 'json' => $json];
    }

    public function reconcile(float $net, float $tax, float $total, float $tolerance = 0.001): void
    {
        // Guard the one-tax-source invariant: the tax read from the order must be
        // non-negative and cannot exceed the order total (a mismatch means the
        // order numbers are inconsistent — hard fail, never a silent adjust).
        if ($tax < -$tolerance || $tax > $total + $tolerance) {
            throw new \RuntimeException(sprintf(
                'JoFotara reconciliation failed: tax=%.3f out of range for total=%.3f.', $tax, $total
            ));
        }
        if (abs(($net + $tax) - $total) > $tolerance) {
            throw new \RuntimeException(sprintf(
                'JoFotara reconciliation failed: net+tax=%.3f but total=%.3f.', $net + $tax, $total
            ));
        }
    }

    /** @param  array<string,mixed>  $f  FiscalInvoiceMath result */
    private function ubl(FiscalReceiptContext $ctx, string $type, string $number, string $uuid, array $f, ?string $ref): string
    {
        $m = fn (int $v): string => FiscalInvoiceMath::fmt($v, 3);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('Invoice');
        $doc->appendChild($root);

        $add = function (string $name, $value, ?\DOMElement $to = null) use ($doc, $root): \DOMElement {
            $el = $doc->createElement($name, htmlspecialchars((string) $value, ENT_XML1));
            ($to ?? $root)->appendChild($el);

            return $el;
        };
        $add('cbc:ID', $number);
        $add('cbc:UUID', $uuid);
        $add('cbc:IssueDate', JordanTime::date($ctx->issuedAt));
        $add('cbc:InvoiceTypeCode', $type);
        $add('cbc:DocumentCurrencyCode', 'JOD');
        if ($ref) {
            $add('cbc:BillingReference', $ref);
        }
        $add('cbc:SellerName', $ctx->sellerName);
        $add('cbc:SellerTaxNumber', (string) $ctx->vatNumber);
        foreach ($f['allowances'] as $a) {
            $ac = $add('cac:AllowanceCharge', '');
            $add('cbc:ChargeIndicator', 'false', $ac);
            $add('cbc:AllowanceChargeReason', 'Discount', $ac);
            $add('cbc:Amount', $m($a['amount']), $ac);
            $add('cbc:TaxCategory', $a['category'], $ac);
            $add('cbc:Percent', number_format($a['rate'], 2, '.', ''), $ac);
        }
        foreach ($f['subtotals'] as $st) {
            $sub = $add('cac:TaxSubtotal', '');
            $add('cbc:TaxableAmount', $m($st['taxable']), $sub);
            $add('cbc:TaxAmount', $m($st['tax']), $sub);
            $add('cbc:TaxCategory', $st['category'], $sub);
            $add('cbc:Percent', number_format($st['rate'], 2, '.', ''), $sub);
        }
        $add('cbc:LineExtensionAmount', $m($f['line_extension']));
        $add('cbc:AllowanceTotalAmount', $m($f['allowance_total']));
        $add('cbc:TaxExclusiveAmount', $m($f['tax_exclusive']));
        $add('cbc:TaxAmount', $m($f['tax_total']));
        $add('cbc:TaxInclusiveAmount', $m($f['tax_inclusive']));
        if ($f['rounding'] !== 0) {
            $add('cbc:PayableRoundingAmount', $m($f['rounding']));
        }
        $add('cbc:PayableAmount', $m($f['payable']));
        foreach ($f['lines'] as $i => $l) {
            $line = $add('cac:InvoiceLine', '');
            $add('cbc:ID', (string) ($i + 1), $line);
            $add('cbc:Name', $l['name'], $line);
            $add('cbc:InvoicedQuantity', (string) $l['qty'], $line);
            $add('cbc:PriceAmount', FiscalInvoiceMath::unitPrice($l, 3), $line);
            if ($l['discount'] !== 0) {
                $add('cbc:AllowanceAmount', $m($l['discount']), $line);
            }
            $add('cbc:LineExtensionAmount', $m($l['net']), $line);
            $add('cbc:TaxCategory', $l['category'], $line);
            $add('cbc:Percent', number_format($l['rate'], 2, '.', ''), $line);
            $add('cbc:TaxAmount', $m($l['tax']), $line);
            $add('cbc:RoundingAmount', $m($l['net'] + $l['tax']), $line);
        }

        return $doc->saveXML();
    }
}
