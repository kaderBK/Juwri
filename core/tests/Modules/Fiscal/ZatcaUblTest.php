<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Services\Zatca\InvoiceHash;
use Juwri\Modules\Fiscal\Services\Zatca\TlvQr;
use Juwri\Modules\Fiscal\Services\Zatca\UblBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\UblInvoice;
use Tests\TestCase;

/**
 * TAX-RUN Z-P1: UBL 2.1 generation, TLV QR vectors, hashing, reconciliation.
 * No DB — pure format/crypto vectors.
 */
class ZatcaUblTest extends TestCase
{
    private function invoice(string $type = 'simplified', ?string $ref = null): UblInvoice
    {
        return new UblInvoice(
            type: $type,
            number: 'SIM-000001',
            uuid: '3cf5ee18-ee25-44ea-a444-2c37ba7f28be',
            issuedAt: new \DateTimeImmutable('2026-07-12T13:30:00+03:00'),
            currency: 'SAR',
            sellerName: 'Al Baik Co',
            sellerVat: '300000000000003',
            sellerCr: '1010101010',
            icv: 5,
            previousHash: base64_encode(hash('sha256', 'prev', true)),
            lines: [
                ['name' => 'Broast', 'qty' => 2, 'unit_price' => 40.0, 'tax_rate' => 15, 'line_total' => 80.0, 'tax_amount' => 12.0],
                ['name' => 'Fries', 'qty' => 1, 'unit_price' => 20.0, 'tax_rate' => 15, 'line_total' => 20.0, 'tax_amount' => 3.0],
            ],
            total: 115.0,
            taxTotal: 15.0,
            referencedNumber: $ref,
            // P6-176: a standard document needs both full parties
            sellerAddress: self::SELLER_ADDRESS, buyer: $type === 'standard' ? self::BUYER : null,
        );
    }

    public const SELLER_ADDRESS = ['street' => 'King Fahd Road', 'building_number' => '1234', 'additional_number' => '5678',
        'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12211', 'country' => 'SA'];

    public const BUYER = ['name' => 'Acme Trading Co', 'vat' => '311111111100003', 'street' => 'Prince Sultan', 'building_number' => '2322',
        'additional_number' => '8228', 'district' => 'Al-Murabba', 'city' => 'Riyadh', 'postal_code' => '23333', 'country' => 'SA'];

    public function test_builds_valid_ubl_with_key_zatca_nodes(): void
    {
        $xml = new UblBuilder()->build($this->invoice());

        $this->assertStringContainsString('<Invoice', $xml);
        $this->assertStringContainsString('<cbc:ID>SIM-000001</cbc:ID>', $xml);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">388</cbc:InvoiceTypeCode>', $xml);
        $this->assertStringContainsString('<cbc:CompanyID>300000000000003</cbc:CompanyID>', $xml);
        $this->assertStringContainsString('<cbc:RegistrationName>Al Baik Co</cbc:RegistrationName>', $xml);
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="SAR">115.00</cbc:PayableAmount>', $xml);
        // Well-formed.
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
    }

    public function test_standard_invoice_uses_subtype_0100000_and_code_388(): void
    {
        $xml = new UblBuilder()->build($this->invoice('standard'));
        $this->assertStringContainsString('name="0100000">388', $xml);
    }

    public function test_credit_note_references_original_and_uses_381(): void
    {
        $xml = new UblBuilder()->build($this->invoice('credit_note', 'SIM-000001'));
        $this->assertStringContainsString('>381<', $xml);
        $this->assertStringContainsString('<cac:BillingReference>', $xml);
        $this->assertStringContainsString('<cbc:ID>SIM-000001</cbc:ID>', $xml);
    }

    public function test_reconciliation_hard_fails_on_mismatch(): void
    {
        $bad = new UblInvoice(
            type: 'simplified', number: 'SIM-1', uuid: 'u', issuedAt: new \DateTimeImmutable(),
            currency: 'SAR', sellerName: 'X', sellerVat: '300000000000003', sellerCr: null,
            icv: 1, previousHash: null,
            lines: [['name' => 'A', 'qty' => 1, 'unit_price' => 10, 'tax_rate' => 15, 'line_total' => 10, 'tax_amount' => 1.5]],
            total: 999.0, taxTotal: 1.5, // deliberately wrong total
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reconciliation failed');
        new UblBuilder()->build($bad);
    }

    public function test_five_tag_tlv_decodes_correctly(): void
    {
        $tlv = TlvQr::fiveTag([
            'seller' => 'Al Baik Co', 'vat' => '300000000000003',
            'timestamp' => '2026-07-12T13:30:00+03:00', 'total' => '115.00', 'vat_amount' => '15.00',
        ]);
        $d = TlvQr::decode($tlv);
        $this->assertSame('Al Baik Co', $d[1]);
        $this->assertSame('300000000000003', $d[2]);
        $this->assertSame('115.00', $d[4]);
        $this->assertSame('15.00', $d[5]);
        $this->assertArrayNotHasKey(6, $d);
    }

    public function test_nine_tag_tlv_includes_crypto_tags(): void
    {
        $digest = hash('sha256', 'invoice-canonical', true);   // raw 32 bytes
        $der = random_bytes(64);                                // raw DER-ish bytes
        $tlv = TlvQr::nineTag(
            ['seller' => 'X', 'vat' => '300000000000003', 'timestamp' => 't', 'total' => '1.00', 'vat_amount' => '0.15'],
            ['hash' => base64_encode($digest), 'signature' => 'SIG', 'public_key' => base64_encode($der), 'ca_signature' => 'CA'],
        );
        $d = TlvQr::decode($tlv);
        $this->assertCount(9, $d);
        // P6-87 (Fatoora SDK encoding): tag 6 is the base64 text of the digest (44 bytes); tag 8 raw DER bytes.
        $this->assertSame(base64_encode($digest), $d[6]);
        $this->assertSame(44, strlen($d[6]));
        $this->assertSame($der, $d[8]);
        $this->assertSame('SIG', $d[7]);
        $this->assertSame('CA', $d[9]);
    }

    public function test_invoice_hash_is_stable_and_excludes_extension_nodes(): void
    {
        $xml = new UblBuilder()->build($this->invoice());
        $h1 = InvoiceHash::base64($xml);
        $h2 = InvoiceHash::base64($xml);
        $this->assertSame($h1, $h2, 'hash deterministic');
        // 32-byte digest → 44-char base64.
        $this->assertSame(44, strlen($h1));
        // Canonical form has the UBLExtensions node removed before hashing.
        $this->assertStringNotContainsString('<ext:UBLExtensions', InvoiceHash::canonicalize($xml));
    }
}
