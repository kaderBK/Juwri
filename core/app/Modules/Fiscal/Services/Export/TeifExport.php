<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Export;

use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;

/**
 * TAX-RUN R-P2: Tunisia TTN "El Fatoora" TEIF (Tunisian Electronic Invoice
 * Format) export seam + ANCE qualified-signature stub. Real transport goes
 * through TTN (the sole intermediary) with an ANCE certificate and a TTN
 * countersignature — a commercial subscription that is a deferred-ledger item.
 * This ships the mappable TEIF document + a signing interface (Fake) so the
 * integration is a drop-in when the subscription lands.
 */
class TeifExport
{
    public static function teif(FiscalReceiptContext $ctx, array $opts = []): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('TEIF');
        $root->setAttribute('version', '1.8.8');
        $root->setAttribute('controlingAgency', 'TTN');
        $doc->appendChild($root);

        $header = $doc->createElement('InvoiceHeader');
        $header->appendChild($doc->createElement('MessageSenderIdentifier', htmlspecialchars((string) $ctx->vatNumber, ENT_XML1)));
        $header->appendChild($doc->createElement('MessageRecieverIdentifier', htmlspecialchars($opts['buyer'] ?? '0000000', ENT_XML1)));
        $root->appendChild($header);

        $body = $doc->createElement('InvoiceBody');
        $body->appendChild($doc->createElement('DocumentIdentifier', htmlspecialchars($opts['number'] ?? 'TN-1', ENT_XML1)));
        $body->appendChild($doc->createElement('DocumentType', 'I-11')); // facture
        $body->appendChild($doc->createElement('SellerName', htmlspecialchars($ctx->sellerName, ENT_XML1)));
        $body->appendChild($doc->createElement('CurrencyCode', 'TND'));
        // P6-99: per-line amounts and VAT per rate (TND, 3 decimals)
        $f = FiscalInvoiceMath::compute($ctx, 3);
        $lines = $doc->createElement('LinSection');
        foreach ($f['lines'] as $i => $l) {
            $lin = $doc->createElement('Lin');
            $lin->appendChild($doc->createElement('ItemIdentifier', (string) ($i + 1)));
            $lin->appendChild($doc->createElement('ItemDescription', htmlspecialchars($l['name'], ENT_XML1)));
            $lin->appendChild($doc->createElement('Quantity', (string) $l['qty']));
            $lin->appendChild($doc->createElement('UnitPrice', FiscalInvoiceMath::unitPrice($l, 3)));
            $lin->appendChild($doc->createElement('Discount', FiscalInvoiceMath::fmt($l['discount'], 3)));
            $lin->appendChild($doc->createElement('TaxRate', number_format($l['rate'], 2, '.', '')));
            $lin->appendChild($doc->createElement('NetAmount', FiscalInvoiceMath::fmt($l['net'], 3)));
            $lin->appendChild($doc->createElement('TaxAmount', FiscalInvoiceMath::fmt($l['tax'], 3)));
            $lines->appendChild($lin);
        }
        $body->appendChild($lines);
        $body->appendChild($doc->createElement('DiscountAmount', FiscalInvoiceMath::fmt($f['allowance_total'], 3)));
        $body->appendChild($doc->createElement('NetAmount', FiscalInvoiceMath::fmt($f['tax_exclusive'], 3)));
        $body->appendChild($doc->createElement('TaxAmount', FiscalInvoiceMath::fmt($f['tax_total'], 3)));
        $body->appendChild($doc->createElement('CapitalAmount', FiscalInvoiceMath::fmt($f['payable'], 3)));
        $root->appendChild($body);

        return $doc->saveXML();
    }
}
