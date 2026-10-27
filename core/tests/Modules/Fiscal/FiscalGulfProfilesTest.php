<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Services\Export\FiscalExport;
use Juwri\Modules\Fiscal\Services\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalRegistry;
use Tests\TestCase;

class FiscalGulfProfilesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function ctx(string $currency = 'AED', float $total = 105, float $tax = 5): FiscalReceiptContext
    {
        return new FiscalReceiptContext(
            sellerName: 'Gulf Diner', vatNumber: '100000000000003', crNumber: null,
            total: $total, taxAmount: $tax, issuedAt: new \DateTimeImmutable('2026-07-12T10:00:00+04:00'),
            currency: $currency,
        );
    }

    public function test_receipt_field_matrix_per_country(): void
    {
        $this->assertContains('simplified_title', FiscalRegistry::profile('AE')->requiredReceiptFields());
        $this->assertContains('simplified_title', FiscalRegistry::profile('BH')->requiredReceiptFields());
        $this->assertContains('qr', FiscalRegistry::profile('OM')->requiredReceiptFields());
        $this->assertSame([], FiscalRegistry::profile('QA')->requiredReceiptFields());
    }

    public function test_uae_threshold_auto_switches_simplified_full(): void
    {
        $ae = FiscalRegistry::profile('AE');
        $this->assertTrue($ae->isSimplified(9999.99));
        $this->assertTrue($ae->isSimplified(10000.0));
        $this->assertFalse($ae->isSimplified(10000.01));
        $this->assertSame('Tax Invoice', $ae->invoiceTitle(false));
    }

    public function test_bahrain_threshold_and_rate(): void
    {
        $bh = FiscalRegistry::profile('BH');
        $this->assertSame(10.0, $bh->defaultVatRate());
        $this->assertSame(500.0, $bh->simplifiedThreshold());
        $this->assertSame(3, $bh->roundingScale());
        $this->assertFalse($bh->isSimplified(500.01));
    }

    public function test_oman_seller_qr_content(): void
    {
        $om = FiscalRegistry::profile('OM');
        $ctx = new FiscalReceiptContext(
            sellerName: 'Muscat Grill', vatNumber: 'OM1234', crNumber: null,
            total: 10.500, taxAmount: 0.500, issuedAt: new \DateTimeImmutable('2026-07-12T10:00:00+04:00'),
            currency: 'OMR', extra: ['seller_uuid' => 'U-1'],
        );
        $qr = $om->qr($ctx);
        $parts = explode('|', $qr);
        $this->assertSame('Muscat Grill', $parts[0]);
        $this->assertSame('OM1234', $parts[1]);
        $this->assertSame('10.500', $parts[3]);
        $this->assertSame('0.500', $parts[4]);
        $this->assertSame('U-1', $parts[5]);
    }

    public function test_qatar_null_profile_zero_rate(): void
    {
        $qa = FiscalRegistry::profile('QA');
        $this->assertSame(0.0, $qa->defaultVatRate());
        $this->assertNull($qa->qr($this->ctx('QAR')));
    }

    public function test_trn_validation(): void
    {
        $this->assertTrue(FiscalRegistry::profile('AE')->validateVatNumber('100000000000003'));
        $this->assertFalse(FiscalRegistry::profile('AE')->validateVatNumber('123')); // not 15 digits
    }

    public function test_generic_ubl_export_seam(): void
    {
        $xml = FiscalExport::ubl($this->ctx('AED', 105, 5), ['number' => 'EXP-9', 'customization' => 'urn:peppol:pint:billing-1:ae-1']);
        $this->assertStringContainsString('<cbc:ID>EXP-9</cbc:ID>', $xml);
        $this->assertStringContainsString('ae-1', $xml);
        $this->assertStringContainsString('<cbc:DocumentCurrencyCode>AED</cbc:DocumentCurrencyCode>', $xml);
        $this->assertStringContainsString('currencyID="AED">105.00', $xml);
        $this->assertTrue(new \DOMDocument()->loadXML($xml));
    }

    public function test_oman_tdd_export_seam(): void
    {
        $tdd = FiscalExport::omanTdd(new FiscalReceiptContext(
            sellerName: 'M', vatNumber: 'OM1', crNumber: null, total: 10.5, taxAmount: 0.5,
            issuedAt: new \DateTimeImmutable(), currency: 'OMR',
        ), ['seller_uuid' => 'U']);
        $this->assertSame('10.500', $tdd['total']);
        $this->assertSame('U', $tdd['sellerUUID']);
    }
}
