<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Services\Zatca\InvoiceHash;
use Juwri\Modules\Fiscal\Services\Zatca\UblBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\UblInvoice;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaParties;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaPartiesMissing;
use Tests\TestCase;

/**
 * P6-176: seller national address + buyer party on ZATCA standard (B2B) documents. No DB — format and rule vectors.
 */
class ZatcaPartiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function invoice(string $type = 'standard', ?array $buyer = ZatcaUblTest::BUYER, array $seller = ZatcaUblTest::SELLER_ADDRESS,
        string $vat = '300000000000003', ?string $ref = null, bool $simplifiedNote = true): UblInvoice
    {
        return new UblInvoice(
            type: $type, number: 'STD-000001', uuid: '3cf5ee18-ee25-44ea-a444-2c37ba7f28be',
            issuedAt: new \DateTimeImmutable('2026-10-14T13:30:00+03:00'), currency: 'SAR',
            sellerName: 'Riyadh Diner Co', sellerVat: $vat, sellerCr: '1010101010', icv: 7, previousHash: null,
            lines: [['name' => 'Kabsa', 'qty' => 2, 'unit_price' => 50.0, 'tax_rate' => 15, 'line_total' => 100.0, 'tax_amount' => 15.0]],
            total: 115.0, taxTotal: 15.0, referencedNumber: $ref, simplifiedNote: $simplifiedNote,
            reason: $ref ? 'Refund' : null, sellerAddress: $seller, buyer: $buyer,
        );
    }

    private function xp(string $xml): \DOMXPath
    {
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('cac', InvoiceHash::NS_CAC);
        $xp->registerNamespace('cbc', InvoiceHash::NS_CBC);

        return $xp;
    }

    public function test_saudi_formats(): void
    {
        foreach (['300000000000003', '399999999900003', '312345678901233'] as $ok) {
            $this->assertTrue(ZatcaParties::validVat($ok), $ok);
        }
        foreach (['', '30000000000003', '3000000000000033', '200000000000003', '300000000000002', '30000000000000A', ' 300000000000003'] as $bad) {
            $this->assertFalse(ZatcaParties::validVat($bad), $bad);
        }
        $this->assertTrue(ZatcaParties::validBuildingNumber('1234'));
        $this->assertFalse(ZatcaParties::validBuildingNumber('123'));
        $this->assertFalse(ZatcaParties::validBuildingNumber('12345'));
        $this->assertFalse(ZatcaParties::validBuildingNumber('12a4'));
        $this->assertTrue(ZatcaParties::validPostalCode('12211'));
        $this->assertFalse(ZatcaParties::validPostalCode('1221'));
        $this->assertFalse(ZatcaParties::validPostalCode('122111'));
    }

    public function test_gaps_name_each_missing_or_malformed_field(): void
    {
        $this->assertSame([], ZatcaParties::standardGaps('Diner', '300000000000003', ZatcaUblTest::SELLER_ADDRESS, ZatcaUblTest::BUYER));
        $this->assertSame(['buyer'], ZatcaParties::standardGaps('Diner', '300000000000003', ZatcaUblTest::SELLER_ADDRESS, null));
        $this->assertSame(
            ['seller.vat', 'seller.street', 'seller.district', 'seller.city', 'seller.postal_code', 'seller.building_number', 'buyer.vat', 'buyer.postal_code'],
            ZatcaParties::standardGaps('Diner', '123', ['building_number' => '12', 'country' => 'SA'],
                ['vat' => '300000000000002', 'postal_code' => '1234'] + ZatcaUblTest::BUYER),
        );
        // the additional number is optional, but 4 digits when given
        $this->assertSame([], ZatcaParties::standardGaps('Diner', '300000000000003', ['additional_number' => ''] + ZatcaUblTest::SELLER_ADDRESS, ZatcaUblTest::BUYER));
        $this->assertSame(['seller.additional_number'], ZatcaParties::standardGaps('Diner', '300000000000003', ['additional_number' => '56'] + ZatcaUblTest::SELLER_ADDRESS, ZatcaUblTest::BUYER));
        // a seller outside Saudi Arabia cannot issue a ZATCA standard invoice
        $this->assertContains('seller.country', ZatcaParties::standardGaps('Diner', '300000000000003', ['country' => 'AE'] + ZatcaUblTest::SELLER_ADDRESS, ZatcaUblTest::BUYER));
        // a buyer abroad: street, city and country (no Saudi national-address fields)
        $foreign = ['name' => 'Gulf LLC', 'vat' => '300000000000003', 'street' => 'Sheikh Zayed Rd', 'city' => 'Dubai', 'country' => 'AE'];
        $this->assertSame([], ZatcaParties::standardGaps('Diner', '300000000000003', ZatcaUblTest::SELLER_ADDRESS, ZatcaParties::buyer($foreign)));
        $this->assertSame(['buyer.name'], ZatcaParties::standardGaps('Diner', '300000000000003', ZatcaUblTest::SELLER_ADDRESS, ['name' => ' '] + ZatcaUblTest::BUYER));
    }

    public function test_buyer_input_is_normalised_never_filled_in(): void
    {
        $b = ZatcaParties::buyer(['name' => ' Acme ', 'vat' => '3111 1111 1100 003', 'city' => 'Riyadh ']);
        $this->assertSame('Acme', $b['name']);
        $this->assertSame('311111111100003', $b['vat']);
        $this->assertSame('SA', $b['country']);
        $this->assertSame('', $b['street']);
        $this->assertSame('', $b['postal_code']);
    }

    public function test_standard_invoice_carries_both_parties_in_ubl_order(): void
    {
        $xml = (new UblBuilder)->build($this->invoice());
        $xp = $this->xp($xml);

        $s = '/*/cac:AccountingSupplierParty/cac:Party';
        $this->assertSame(['PartyIdentification', 'PostalAddress', 'PartyTaxScheme', 'PartyLegalEntity'],
            array_map(fn ($n) => $n->localName, iterator_to_array($xp->query($s.'/*'))));
        $this->assertSame('1010101010', $xp->evaluate("string($s/cac:PartyIdentification/cbc:ID[@schemeID='CRN'])"));
        $this->assertSame(['StreetName', 'BuildingNumber', 'PlotIdentification', 'CitySubdivisionName', 'CityName', 'PostalZone', 'Country'],
            array_map(fn ($n) => $n->localName, iterator_to_array($xp->query($s.'/cac:PostalAddress/*'))));
        $this->assertSame('SA', $xp->evaluate("string($s/cac:PostalAddress/cac:Country/cbc:IdentificationCode)"));

        $b = '/*/cac:AccountingCustomerParty/cac:Party';
        $this->assertSame(['PostalAddress', 'PartyTaxScheme', 'PartyLegalEntity'],
            array_map(fn ($n) => $n->localName, iterator_to_array($xp->query($b.'/*'))));
        // P6-186: a Saudi buyer's additional number is required (BR-KSA-63)
        $this->assertSame(['StreetName', 'BuildingNumber', 'PlotIdentification', 'CitySubdivisionName', 'CityName', 'PostalZone', 'Country'],
            array_map(fn ($n) => $n->localName, iterator_to_array($xp->query($b.'/cac:PostalAddress/*'))));
        $this->assertSame('311111111100003', $xp->evaluate("string($b/cac:PartyTaxScheme/cbc:CompanyID)"));
        $this->assertSame('VAT', $xp->evaluate("string($b/cac:PartyTaxScheme/cac:TaxScheme/cbc:ID)"));
        $this->assertSame('Acme Trading Co', $xp->evaluate("string($b/cac:PartyLegalEntity/cbc:RegistrationName)"));

        // KSA-5 supply date, and the UBL sequence of the root children
        $this->assertSame('2026-10-14', $xp->evaluate('string(/*/cac:Delivery/cbc:ActualDeliveryDate)'));
        $order = array_values(array_unique(array_map(fn ($n) => $n->localName, iterator_to_array($xp->query('/*/*')))));
        $this->assertSame(['ProfileID', 'ID', 'UUID', 'IssueDate', 'IssueTime', 'InvoiceTypeCode', 'DocumentCurrencyCode', 'TaxCurrencyCode',
            'AdditionalDocumentReference', 'AccountingSupplierParty', 'AccountingCustomerParty', 'Delivery', 'PaymentMeans', 'TaxTotal', 'LegalMonetaryTotal', 'InvoiceLine'], $order); // P6-186: BR-KSA-16
    }

    public function test_simplified_invoice_has_an_empty_buyer_party_and_no_supply_date(): void
    {
        $xp = $this->xp((new UblBuilder)->build($this->invoice('simplified', null)));
        $this->assertSame(1, (int) $xp->evaluate('count(/*/cac:AccountingCustomerParty)'));
        $this->assertSame(0, (int) $xp->evaluate('count(/*/cac:AccountingCustomerParty/*)'));
        $this->assertSame(0, (int) $xp->evaluate('count(/*/cac:Delivery)'));
        // the seller address still shows when the store gave it
        $this->assertSame('12211', $xp->evaluate('string(/*/cac:AccountingSupplierParty//cbc:PostalZone)'));

        // a simplified invoice of a store without an address: no empty PostalAddress
        $xp = $this->xp((new UblBuilder)->build($this->invoice('simplified', null, ['country' => 'SA'])));
        $this->assertSame(0, (int) $xp->evaluate('count(//cac:PostalAddress)'));
    }

    public function test_a_note_puts_the_billing_reference_before_the_document_references(): void
    {
        $xp = $this->xp((new UblBuilder)->build($this->invoice('credit_note', ZatcaUblTest::BUYER, ref: 'STD-000001', simplifiedNote: false)));
        $names = array_map(fn ($n) => $n->localName, iterator_to_array($xp->query('/*/*')));
        $this->assertLessThan(array_search('AdditionalDocumentReference', $names, true), array_search('BillingReference', $names, true));
        // a note of a standard invoice is standard and names the buyer; no supply date on a note
        $this->assertSame('0100000', $xp->evaluate('string(/*/cbc:InvoiceTypeCode/@name)'));
        $this->assertSame('311111111100003', $xp->evaluate('string(/*/cac:AccountingCustomerParty//cbc:CompanyID)'));
        $this->assertSame(0, (int) $xp->evaluate('count(/*/cac:Delivery)'));
    }

    public function test_a_standard_document_without_full_parties_is_never_built(): void
    {
        $cases = [
            'no buyer' => [$this->invoice('standard', null), ['buyer']],
            'no seller address' => [$this->invoice('standard', ZatcaUblTest::BUYER, ['country' => 'SA']),
                ['seller.street', 'seller.building_number', 'seller.district', 'seller.city', 'seller.postal_code']],
            'bad seller VAT' => [$this->invoice('standard', ZatcaUblTest::BUYER, ZatcaUblTest::SELLER_ADDRESS, '300000000000001'), ['seller.vat']],
            'buyer without VAT' => [$this->invoice('standard', ['vat' => ''] + ZatcaUblTest::BUYER), ['buyer.vat']],
            'note of a standard invoice without buyer' => [$this->invoice('credit_note', null, ref: 'STD-1', simplifiedNote: false), ['buyer']],
        ];
        foreach ($cases as $name => [$inv, $gaps]) {
            try {
                (new UblBuilder)->build($inv);
                $this->fail($name.': built');
            } catch (ZatcaPartiesMissing $e) {
                $this->assertSame($gaps, $e->gaps, $name);
                $this->assertStringContainsString('The tax invoice was not issued', $e->getMessage(), $e->getMessage());
            }
        }
        // the simplified document and its note stay as they were: no buyer needed
        $this->assertStringContainsString('name="0200000">381', (new UblBuilder)->build($this->invoice('credit_note', null, ref: 'SIM-1')));
    }

    /** Golden: the exact party XML of a standard invoice. */
    public function test_golden_party_xml(): void
    {
        $xml = (new UblBuilder)->build($this->invoice());
        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        $doc->formatOutput = false;
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('cac', InvoiceHash::NS_CAC);
        $flat = fn (string $q) => preg_replace('/>\s+</', '><', $doc->saveXML($xp->query($q)->item(0)));

        $this->assertSame(
            '<cac:AccountingSupplierParty><cac:Party><cac:PartyIdentification><cbc:ID schemeID="CRN">1010101010</cbc:ID></cac:PartyIdentification>'
            .'<cac:PostalAddress><cbc:StreetName>King Fahd Road</cbc:StreetName><cbc:BuildingNumber>1234</cbc:BuildingNumber>'
            .'<cbc:PlotIdentification>5678</cbc:PlotIdentification><cbc:CitySubdivisionName>Al Olaya</cbc:CitySubdivisionName>'
            .'<cbc:CityName>Riyadh</cbc:CityName><cbc:PostalZone>12211</cbc:PostalZone><cac:Country><cbc:IdentificationCode>SA</cbc:IdentificationCode></cac:Country></cac:PostalAddress>'
            .'<cac:PartyTaxScheme><cbc:CompanyID>300000000000003</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>'
            .'<cac:PartyLegalEntity><cbc:RegistrationName>Riyadh Diner Co</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:AccountingSupplierParty>',
            $flat('/*/cac:AccountingSupplierParty'),
        );
        $this->assertSame(
            '<cac:AccountingCustomerParty><cac:Party><cac:PostalAddress><cbc:StreetName>Prince Sultan</cbc:StreetName><cbc:BuildingNumber>2322</cbc:BuildingNumber>'
            .'<cbc:PlotIdentification>8228</cbc:PlotIdentification><cbc:CitySubdivisionName>Al-Murabba</cbc:CitySubdivisionName><cbc:CityName>Riyadh</cbc:CityName><cbc:PostalZone>23333</cbc:PostalZone>'
            .'<cac:Country><cbc:IdentificationCode>SA</cbc:IdentificationCode></cac:Country></cac:PostalAddress>'
            .'<cac:PartyTaxScheme><cbc:CompanyID>311111111100003</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>'
            .'<cac:PartyLegalEntity><cbc:RegistrationName>Acme Trading Co</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:AccountingCustomerParty>',
            $flat('/*/cac:AccountingCustomerParty'),
        );
        // the amounts are untouched by the parties
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="SAR">115.00</cbc:PayableAmount>', $xml);
    }
}
