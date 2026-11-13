<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;

/**
 * TAX-RUN Z-P1: builds a ZATCA-compliant UBL 2.1 invoice (simplified, standard,
 * or credit note) from a UblInvoice value object.
 *
 * The builder READS order-derived totals and formats them per the profile — it
 * never recomputes tax. A reconciliation assert guarantees the document total
 * equals the order total within the profile's rounding tolerance; a mismatch is
 * a HARD FAIL (never a silent adjust), protecting the one-tax-source invariant.
 */
class UblBuilder
{
    public const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const INV = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    /** @throws \RuntimeException on reconciliation failure. */
    public function build(UblInvoice $inv, float $tolerance = 0.02): string
    {
        $this->reconcile($inv, $tolerance);
        // P6-176: a standard (B2B) document is never built without both full parties — no invented data, no invalid XML
        if ($inv->isStandardDocument()) {
            ZatcaParties::assertStandard($inv->sellerName, $inv->sellerVat, $inv->sellerAddress, $inv->buyer);
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(self::INV, 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC);
        // P6-87: ext is declared on the root up front (as in ZATCA's samples) — root namespace declarations are part of the
        // canonical hash input, so the hash must not change when XadesStamp later inserts ext:UBLExtensions.
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', InvoiceHash::NS_EXT);
        $doc->appendChild($root);
        // P6-87: no empty extension placeholder (an empty ext:UBLExtensions is schema-invalid); XadesStamp inserts the
        // signed ext:UBLExtensions as the first child.

        $this->cbc($doc, $root, 'ProfileID', 'reporting:1.0');
        $this->cbc($doc, $root, 'ID', $inv->number);
        $this->cbc($doc, $root, 'UUID', $inv->uuid);
        $this->cbc($doc, $root, 'IssueDate', KsaTime::date($inv->issuedAt));
        $this->cbc($doc, $root, 'IssueTime', KsaTime::time($inv->issuedAt)); // BR-KSA-70: AST, see KsaTime
        $this->cbc($doc, $root, 'InvoiceTypeCode', $inv->typeCode(), ['name' => $inv->typeSubCode()]);
        $this->cbc($doc, $root, 'DocumentCurrencyCode', $inv->currency);
        $this->cbc($doc, $root, 'TaxCurrencyCode', $inv->currency);

        // Credit note reference to the original invoice. P6-176: UBL 2.1 puts cac:BillingReference BEFORE
        // cac:AdditionalDocumentReference (schema sequence; it was emitted after the ICV/PIH references).
        if ($inv->isNote() && $inv->referencedNumber) {
            $bill = $doc->createElement('cac:BillingReference');
            $ref = $doc->createElement('cac:InvoiceDocumentReference');
            $this->cbc($doc, $ref, 'ID', $inv->referencedNumber);
            $bill->appendChild($ref);
            $root->appendChild($bill);
        }

        // ICV + PIH (AdditionalDocumentReference nodes; PIH excluded from hash).
        $this->docRef($doc, $root, 'ICV', (string) $inv->icv);
        $this->docRef($doc, $root, 'PIH', $inv->previousHash ?? \Juwri\Modules\Fiscal\Services\Zatca\InvoiceHash::FIRST_PIH, true);

        $this->sellerParty($doc, $root, $inv);
        $this->customerParty($doc, $root, $inv);
        // P6-176: KSA-5 supply date on a standard tax invoice (BR-KSA-15) — the sale is supplied when it is issued
        if ($inv->isStandardDocument() && ! $inv->isNote()) {
            $delivery = $doc->createElement('cac:Delivery');
            $this->cbc($doc, $delivery, 'ActualDeliveryDate', KsaTime::date($inv->issuedAt));
            $root->appendChild($delivery);
        }
        // P4-01: KSA-10 — a credit note carries the reason it was issued (cac:PaymentMeans/cbc:InstructionNote).
        // P6-186: every document carries its payment means code (SDK 203 BR-KSA-16: 10/30/42/48/1).
        $means = $doc->createElement('cac:PaymentMeans');
        $this->cbc($doc, $means, 'PaymentMeansCode', $inv->isNote() ? '10' : (in_array($inv->paymentMeansCode, ['1', '10', '30', '42', '48'], true) ? $inv->paymentMeansCode : '1'));
        if ($inv->isNote()) {
            $this->cbc($doc, $means, 'InstructionNote', mb_substr(trim((string) $inv->reason) !== '' ? (string) $inv->reason : 'Refund', 0, 1000));
        }
        $root->appendChild($means);
        $fiscal = $inv->fiscal();
        foreach ($fiscal['allowances'] as $a) {
            $this->allowance($doc, $root, $a, $inv->currency);
        }
        $this->taxTotal($doc, $root, $inv, $fiscal);
        $this->legalTotal($doc, $root, $inv, $fiscal);
        foreach ($fiscal['lines'] as $i => $line) {
            $this->invoiceLine($doc, $root, $i + 1, $line, $inv->currency);
        }

        return $doc->saveXML();
    }

    /** Hard-fail if the formatted document total drifts from the order total. */
    public function reconcile(UblInvoice $inv, float $tolerance): void
    {
        $f = $inv->fiscal();
        $computed = ($f['tax_inclusive'] + $f['rounding'] + $f['excluded']) / 100;
        if (abs($computed - $inv->total) > $tolerance) {
            throw new \RuntimeException(sprintf(
                'ZATCA UBL reconciliation failed: lines+tax=%.4f but order total=%.4f (tolerance %.4f).',
                $computed, $inv->total, $tolerance
            ));
        }
    }

    private function sellerParty(\DOMDocument $doc, \DOMElement $root, UblInvoice $inv): void
    {
        $supplier = $doc->createElement('cac:AccountingSupplierParty');
        $party = $doc->createElement('cac:Party');

        if ($inv->sellerCr) {
            $pid = $doc->createElement('cac:PartyIdentification');
            $this->cbc($doc, $pid, 'ID', $inv->sellerCr, ['schemeID' => 'CRN']);
            $party->appendChild($pid);
        }

        // P6-176: BG-5 seller postal address (BR-KSA-09), when the store gave it (a standard document requires it)
        if (array_filter($inv->sellerAddress, fn ($v, $k) => $k !== 'country' && trim((string) $v) !== '', ARRAY_FILTER_USE_BOTH) !== []) {
            $party->appendChild($this->postalAddress($doc, $inv->sellerAddress));
        }

        $this->partyTax($doc, $party, $inv->sellerVat, $inv->sellerName);

        $supplier->appendChild($party);
        $root->appendChild($supplier);
    }

    /**
     * P6-176: BG-7 buyer. UBL 2.1 requires cac:AccountingCustomerParty on every invoice; a simplified (B2C) document
     * without a buyer carries it empty, a standard one carries the buyer's address, VAT number and name.
     */
    private function customerParty(\DOMDocument $doc, \DOMElement $root, UblInvoice $inv): void
    {
        $customer = $doc->createElement('cac:AccountingCustomerParty');
        $b = $inv->buyer;
        if ($b !== null && trim((string) ($b['name'] ?? '')) !== '') {
            $party = $doc->createElement('cac:Party');
            $party->appendChild($this->postalAddress($doc, $b));
            $this->partyTax($doc, $party, trim((string) ($b['vat'] ?? '')), (string) $b['name']);
            $customer->appendChild($party);
        }
        $root->appendChild($customer);
    }

    /** cac:PartyTaxScheme (VAT number, when given) + cac:PartyLegalEntity (registration name). */
    private function partyTax(\DOMDocument $doc, \DOMElement $party, string $vat, string $name): void
    {
        if ($vat !== '') {
            $taxScheme = $doc->createElement('cac:PartyTaxScheme');
            $this->cbc($doc, $taxScheme, 'CompanyID', $vat);
            $scheme = $doc->createElement('cac:TaxScheme');
            $this->cbc($doc, $scheme, 'ID', 'VAT');
            $taxScheme->appendChild($scheme);
            $party->appendChild($taxScheme);
        }

        $legal = $doc->createElement('cac:PartyLegalEntity');
        $this->cbc($doc, $legal, 'RegistrationName', $name);
        $party->appendChild($legal);
    }

    /**
     * cac:PostalAddress in UBL sequence: StreetName (BT-35/50), BuildingNumber (KSA-17/18), PlotIdentification (KSA-23/19,
     * additional number), CitySubdivisionName (KSA-3/4, district), CityName (BT-37/52), PostalZone (BT-38/53),
     * Country/IdentificationCode (BT-40/55). Empty fields are left out, never filled in.
     *
     * @param  array<string,mixed>  $a
     */
    private function postalAddress(\DOMDocument $doc, array $a): \DOMElement
    {
        $addr = $doc->createElement('cac:PostalAddress');
        foreach (['street' => 'StreetName', 'building_number' => 'BuildingNumber', 'additional_number' => 'PlotIdentification',
            'district' => 'CitySubdivisionName', 'city' => 'CityName', 'postal_code' => 'PostalZone', 'region' => 'CountrySubentity'] as $k => $el) {
            $v = trim((string) ($a[$k] ?? ''));
            if ($v !== '') {
                $this->cbc($doc, $addr, $el, $v);
            }
        }
        $country = strtoupper(trim((string) ($a['country'] ?? '')));
        if ($country !== '') {
            $c = $doc->createElement('cac:Country');
            $this->cbc($doc, $c, 'IdentificationCode', $country);
            $addr->appendChild($c);
        }

        return $addr;
    }

    /**
     * P6-99: two TaxTotal blocks (BR-KSA-EN16931-08/09): the first carries one TaxSubtotal per VAT category/rate, the
     * second the VAT in the tax currency (SAR) alone.
     *
     * @param  array<string,mixed>  $f
     */
    private function taxTotal(\DOMDocument $doc, \DOMElement $root, UblInvoice $inv, array $f): void
    {
        $tt = $doc->createElement('cac:TaxTotal');
        $this->cbc($doc, $tt, 'TaxAmount', $this->minor($f['tax_total']), ['currencyID' => $inv->currency]);
        foreach ($f['subtotals'] as $st) {
            $sub = $doc->createElement('cac:TaxSubtotal');
            $this->cbc($doc, $sub, 'TaxableAmount', $this->minor($st['taxable']), ['currencyID' => $inv->currency]);
            $this->cbc($doc, $sub, 'TaxAmount', $this->minor($st['tax']), ['currencyID' => $inv->currency]);
            $sub->appendChild($this->taxCategory($doc, 'cac:TaxCategory', $st, true));
            $tt->appendChild($sub);
        }
        $root->appendChild($tt);

        $tt2 = $doc->createElement('cac:TaxTotal');
        $this->cbc($doc, $tt2, 'TaxAmount', $this->minor($f['tax_total']), ['currencyID' => $inv->currency]);
        $root->appendChild($tt2);
    }

    /** @param  array<string,mixed>  $f */
    private function legalTotal(\DOMDocument $doc, \DOMElement $root, UblInvoice $inv, array $f): void
    {
        $c = ['currencyID' => $inv->currency];
        $lmt = $doc->createElement('cac:LegalMonetaryTotal');
        $this->cbc($doc, $lmt, 'LineExtensionAmount', $this->minor($f['line_extension']), $c);
        $this->cbc($doc, $lmt, 'TaxExclusiveAmount', $this->minor($f['tax_exclusive']), $c);
        $this->cbc($doc, $lmt, 'TaxInclusiveAmount', $this->minor($f['tax_inclusive']), $c);
        $this->cbc($doc, $lmt, 'AllowanceTotalAmount', $this->minor($f['allowance_total']), $c);
        if ($f['rounding'] !== 0) {
            $this->cbc($doc, $lmt, 'PayableRoundingAmount', $this->minor($f['rounding']), $c);
        }
        $this->cbc($doc, $lmt, 'PayableAmount', $this->minor($f['payable']), $c);
        $root->appendChild($lmt);
    }

    /** @param  array<string,mixed>  $a  document-level allowance (the order discount, per VAT category) */
    private function allowance(\DOMDocument $doc, \DOMElement $root, array $a, string $currency): void
    {
        $ac = $doc->createElement('cac:AllowanceCharge');
        $this->cbc($doc, $ac, 'ChargeIndicator', 'false');
        $this->cbc($doc, $ac, 'AllowanceChargeReasonCode', '95');
        $this->cbc($doc, $ac, 'AllowanceChargeReason', 'Discount');
        $this->cbc($doc, $ac, 'Amount', $this->minor($a['amount']), ['currencyID' => $currency]);
        $ac->appendChild($this->taxCategory($doc, 'cac:TaxCategory', $a, false));
        $root->appendChild($ac);
    }

    /** @param  array<string,mixed>  $line */
    private function invoiceLine(\DOMDocument $doc, \DOMElement $root, int $id, array $line, string $currency): void
    {
        $c = ['currencyID' => $currency];
        $il = $doc->createElement('cac:InvoiceLine');
        $this->cbc($doc, $il, 'ID', (string) $id);
        $this->cbc($doc, $il, 'InvoicedQuantity', $this->qty((float) $line['qty']), ['unitCode' => 'PCE']);
        $this->cbc($doc, $il, 'LineExtensionAmount', $this->minor($line['net']), $c);
        // P6-186 (SDK 203): a line discount that divides evenly per unit goes on the price (cac:Price/cac:AllowanceCharge,
        // net price + per-unit discount, as ZATCA's own samples); SDK 203 fails with a Saxon error when two
        // or more lines carry cac:InvoiceLine/cac:AllowanceCharge. Uneven per-unit discounts stay on the line (BG-27).
        $qty = (float) $line['qty'];
        $perUnit = $line['discount'] !== 0 && $qty >= 1 && fmod($qty, 1.0) === 0.0 && $line['discount'] % (int) $qty === 0
            && ($line['gross'] ?? null) !== null && $line['gross'] % (int) $qty === 0;
        if ($line['discount'] !== 0 && ! $perUnit) {
            $ac = $doc->createElement('cac:AllowanceCharge');
            $this->cbc($doc, $ac, 'ChargeIndicator', 'false');
            $this->cbc($doc, $ac, 'AllowanceChargeReasonCode', '95');
            $this->cbc($doc, $ac, 'AllowanceChargeReason', 'Discount');
            $this->cbc($doc, $ac, 'Amount', $this->minor($line['discount']), $c);
            $il->appendChild($ac);
        }
        // KSA-11/KSA-12: line VAT and line amount with VAT
        $tt = $doc->createElement('cac:TaxTotal');
        $this->cbc($doc, $tt, 'TaxAmount', $this->minor($line['tax']), $c);
        $this->cbc($doc, $tt, 'RoundingAmount', $this->minor($line['net'] + $line['tax']), $c);
        $il->appendChild($tt);

        $item = $doc->createElement('cac:Item');
        $this->cbc($doc, $item, 'Name', (string) $line['name']);
        $item->appendChild($this->taxCategory($doc, 'cac:ClassifiedTaxCategory', $line, false));
        $il->appendChild($item);

        $price = $doc->createElement('cac:Price');
        if ($perUnit) {
            $q = (int) $qty;
            $grossUnit = intdiv((int) $line['gross'], $q);
            $discUnit = intdiv((int) $line['discount'], $q);
            $this->cbc($doc, $price, 'PriceAmount', $this->minor($grossUnit - $discUnit), $c);
            $pac = $doc->createElement('cac:AllowanceCharge');
            $this->cbc($doc, $pac, 'ChargeIndicator', 'false');
            $this->cbc($doc, $pac, 'AllowanceChargeReason', 'Discount');
            // no BaseAmount: SDK 203 wants a MultiplierFactorNumeric with it (BR-KSA-EN16931-04), and a percentage would round
            $this->cbc($doc, $pac, 'Amount', $this->minor($discUnit), $c);
            $price->appendChild($pac);
        } else {
            $this->cbc($doc, $price, 'PriceAmount', FiscalInvoiceMath::unitPrice($line, 2), $c);
        }
        $il->appendChild($price);

        $root->appendChild($il);
    }

    /** @param  array<string,mixed>  $t  category, rate, exemption_code, exemption_reason */
    private function taxCategory(\DOMDocument $doc, string $name, array $t, bool $withExemption): \DOMElement
    {
        $cat = $doc->createElement($name);
        $this->cbc($doc, $cat, 'ID', (string) $t['category']);
        $this->cbc($doc, $cat, 'Percent', number_format((float) $t['rate'], 2, '.', ''));
        // P6-117: every Z/E/O subtotal carries a VATEX code of its own category (BR-KSA-69, BR-Z/E/O-10)
        $vatex = Vatex::resolve((string) $t['category'], $t['exemption_code'] ?? null, $t['exemption_reason'] ?? null);
        if ($withExemption && $vatex !== null) {
            $this->cbc($doc, $cat, 'TaxExemptionReasonCode', $vatex[0]);
            $this->cbc($doc, $cat, 'TaxExemptionReason', $vatex[1]);
        }
        $scheme = $doc->createElement('cac:TaxScheme');
        $this->cbc($doc, $scheme, 'ID', 'VAT');
        $cat->appendChild($scheme);

        return $cat;
    }

    private function docRef(\DOMDocument $doc, \DOMElement $root, string $id, string $value, bool $embedInAttachment = false): void
    {
        $ref = $doc->createElement('cac:AdditionalDocumentReference');
        $this->cbc($doc, $ref, 'ID', $id);
        if ($embedInAttachment) {
            $att = $doc->createElement('cac:Attachment');
            $this->cbc($doc, $att, 'EmbeddedDocumentBinaryObject', $value, ['mimeCode' => 'text/plain']);
            $ref->appendChild($att);
        } else {
            $this->cbc($doc, $ref, 'UUID', $value);
        }
        $root->appendChild($ref);
    }

    private function cbc(\DOMDocument $doc, \DOMElement $parent, string $name, string $value, array $attrs = []): void
    {
        $el = $doc->createElement('cbc:'.$name, htmlspecialchars($value, ENT_XML1));
        foreach ($attrs as $k => $v) {
            $el->setAttribute($k, $v);
        }
        $parent->appendChild($el);
    }

    private function minor(int $v): string
    {
        return FiscalInvoiceMath::fmt($v, 2);
    }

    private function qty(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') ?: '0';
    }
}
