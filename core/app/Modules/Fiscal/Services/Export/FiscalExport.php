<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Export;

use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;

/**
 * TAX-RUN R-P1: generic UBL 2.1 / Peppol-PINT export serializer for the
 * format-ready profiles (UAE PINT-AE, Oman PINT-OM, Morocco UBL). These
 * countries transport through Accredited Service Providers (BD partnerships),
 * NOT directly — so this run ships the mappable document seam only. The actual
 * ASP/SP transport is a deferred-ledger item.
 */
class FiscalExport
{
    /**
     * Minimal, provider-mappable UBL 2.1 for a document. Carries the fields a
     * PINT profile needs (party identity, currency, tax + monetary totals).
     */
    public static function ubl(FiscalReceiptContext $ctx, array $opts = []): string
    {
        $currency = $ctx->currency;
        // P6-99: per-line document — lines, discount allowance, TaxSubtotal per category, rounding; in the currency's
        // own minor unit (AED 2, OMR 3, MAD 2).
        $d = money_decimals($currency);
        $f = FiscalInvoiceMath::compute($ctx, $d);
        $m = fn (int $v): string => FiscalInvoiceMath::fmt($v, $d);
        $c = ['currencyID' => $currency];

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $cbcNs = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
        $cacNs = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
        $inv = $doc->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice');
        $inv->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', $cbcNs);
        $inv->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', $cacNs);
        $doc->appendChild($inv);

        $add = function (string $name, $value = null, array $attrs = [], ?\DOMElement $to = null) use ($doc, $inv): \DOMElement {
            $el = $value === null ? $doc->createElement($name) : $doc->createElement($name, htmlspecialchars((string) $value, ENT_XML1));
            foreach ($attrs as $k => $v) {
                $el->setAttribute($k, (string) $v);
            }
            ($to ?? $inv)->appendChild($el);

            return $el;
        };
        $category = function (string $name, array $t, \DOMElement $to, bool $exemption) use ($add): void {
            $cat = $add($name, null, [], $to);
            $add('cbc:ID', $t['category'], [], $cat);
            $add('cbc:Percent', number_format((float) $t['rate'], 2, '.', ''), [], $cat);
            if ($exemption && $t['category'] !== 'S' && ! empty($t['exemption_code'])) {
                $add('cbc:TaxExemptionReasonCode', $t['exemption_code'], [], $cat);
                $add('cbc:TaxExemptionReason', (string) ($t['exemption_reason'] ?? ''), [], $cat);
            }
            $add('cbc:ID', 'VAT', [], $add('cac:TaxScheme', null, [], $cat));
        };

        $add('cbc:CustomizationID', $opts['customization'] ?? 'urn:peppol:pint:billing-1');
        $add('cbc:ID', $opts['number'] ?? 'EXP-1');
        $add('cbc:IssueDate', $ctx->issuedAt->format('Y-m-d'));
        $add('cbc:InvoiceTypeCode', $opts['type_code'] ?? '388');
        $add('cbc:DocumentCurrencyCode', $currency);
        $party = $add('cac:Party', null, [], $add('cac:AccountingSupplierParty'));
        $pts = $add('cac:PartyTaxScheme', null, [], $party);
        $add('cbc:CompanyID', (string) $ctx->vatNumber, [], $pts);
        $add('cbc:ID', 'VAT', [], $add('cac:TaxScheme', null, [], $pts));
        $add('cbc:RegistrationName', $ctx->sellerName, [], $add('cac:PartyLegalEntity', null, [], $party));

        foreach ($f['allowances'] as $a) {
            $ac = $add('cac:AllowanceCharge');
            $add('cbc:ChargeIndicator', 'false', [], $ac);
            $add('cbc:AllowanceChargeReasonCode', '95', [], $ac);
            $add('cbc:AllowanceChargeReason', 'Discount', [], $ac);
            $add('cbc:Amount', $m($a['amount']), $c, $ac);
            $category('cac:TaxCategory', $a, $ac, false);
        }
        $tt = $add('cac:TaxTotal');
        $add('cbc:TaxAmount', $m($f['tax_total']), $c, $tt);
        foreach ($f['subtotals'] as $st) {
            $sub = $add('cac:TaxSubtotal', null, [], $tt);
            $add('cbc:TaxableAmount', $m($st['taxable']), $c, $sub);
            $add('cbc:TaxAmount', $m($st['tax']), $c, $sub);
            $category('cac:TaxCategory', $st, $sub, true);
        }
        $lmt = $add('cac:LegalMonetaryTotal');
        $add('cbc:LineExtensionAmount', $m($f['line_extension']), $c, $lmt);
        $add('cbc:TaxExclusiveAmount', $m($f['tax_exclusive']), $c, $lmt);
        $add('cbc:TaxInclusiveAmount', $m($f['tax_inclusive']), $c, $lmt);
        $add('cbc:AllowanceTotalAmount', $m($f['allowance_total']), $c, $lmt);
        if ($f['rounding'] !== 0) {
            $add('cbc:PayableRoundingAmount', $m($f['rounding']), $c, $lmt);
        }
        $add('cbc:PayableAmount', $m($f['payable']), $c, $lmt);

        foreach ($f['lines'] as $i => $l) {
            $il = $add('cac:InvoiceLine');
            $add('cbc:ID', (string) ($i + 1), [], $il);
            $add('cbc:InvoicedQuantity', (string) $l['qty'], ['unitCode' => 'C62'], $il);
            $add('cbc:LineExtensionAmount', $m($l['net']), $c, $il);
            if ($l['discount'] !== 0) {
                $ac = $add('cac:AllowanceCharge', null, [], $il);
                $add('cbc:ChargeIndicator', 'false', [], $ac);
                $add('cbc:AllowanceChargeReason', 'Discount', [], $ac);
                $add('cbc:Amount', $m($l['discount']), $c, $ac);
            }
            $item = $add('cac:Item', null, [], $il);
            $add('cbc:Name', $l['name'], [], $item);
            $category('cac:ClassifiedTaxCategory', $l, $item, false);
            $add('cbc:PriceAmount', FiscalInvoiceMath::unitPrice($l, $d), $c, $add('cac:Price', null, [], $il));
        }

        return $doc->saveXML();
    }

    /**
     * Oman TDD (Tax Data Document) report envelope seam — the JSON the SP submits
     * within 24h. Provider transport deferred; the payload shape is here.
     */
    public static function omanTdd(FiscalReceiptContext $ctx, array $opts = []): array
    {
        return [
            'sellerVAT' => $ctx->vatNumber,
            'sellerName' => $ctx->sellerName,
            'issued' => $ctx->issuedAt->format('c'),
            'total' => FiscalInvoiceMath::fmt(($f = FiscalInvoiceMath::compute($ctx, 3))['payable'], 3),
            'vat' => FiscalInvoiceMath::fmt($f['tax_total'], 3),
            'lines' => array_map(fn (array $l) => [ // P6-99
                'name' => $l['name'], 'qty' => $l['qty'], 'net' => FiscalInvoiceMath::fmt($l['net'], 3),
                'category' => $l['category'], 'rate' => $l['rate'], 'vat' => FiscalInvoiceMath::fmt($l['tax'], 3),
            ], $f['lines']),
            'sellerUUID' => $opts['seller_uuid'] ?? '',
        ];
    }
}
