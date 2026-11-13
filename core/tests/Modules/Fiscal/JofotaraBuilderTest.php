<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Services\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\Jofotara\FakeJofotaraClient;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraBuilder;
use Tests\TestCase;

class JofotaraBuilderTest extends TestCase
{
    private function ctx(float $total = 116.0, float $tax = 16.0): FiscalReceiptContext
    {
        return new FiscalReceiptContext(
            sellerName: 'Amman Grill', vatNumber: '12345678', crNumber: null,
            total: $total, taxAmount: $tax, issuedAt: new \DateTimeImmutable('2026-07-12T10:00:00+03:00'),
            currency: 'JOD',
        );
    }

    public function test_builds_ubl_and_json_envelope(): void
    {
        $out = new JofotaraBuilder()->build($this->ctx(), [
            'type' => JofotaraBuilder::INVOICE_CASH, 'number' => 'INV-100', 'uuid' => 'u-1', 'activity_number' => 'ACT-9',
        ]);

        $this->assertStringContainsString('<cbc:ID>INV-100</cbc:ID>', $out['ubl']);
        $this->assertStringContainsString('<cbc:SellerTaxNumber>12345678</cbc:SellerTaxNumber>', $out['ubl']);
        $this->assertSame('012', $out['json']['invoiceType']);
        $this->assertSame('ACT-9', $out['json']['activityNumber']);
        $this->assertSame('100.000', $out['json']['totals']['taxExclusive']);
        $this->assertSame('16.000', $out['json']['totals']['taxAmount']);
        $this->assertSame('116.000', $out['json']['totals']['taxInclusive']);
        // UBL embedded base64 round-trips.
        $this->assertStringContainsString('INV-100', base64_decode($out['json']['invoice']));
    }

    public function test_credit_note_carries_reference(): void
    {
        $out = new JofotaraBuilder()->build($this->ctx(), [
            'type' => JofotaraBuilder::CREDIT_NOTE, 'number' => 'CN-1', 'reference' => 'INV-100',
        ]);
        $this->assertSame('381', $out['json']['invoiceType']);
        $this->assertSame('INV-100', $out['json']['reference']);
        $this->assertStringContainsString('INV-100', $out['ubl']);
    }

    public function test_reconciliation_hard_fails(): void
    {
        // Tax exceeding the order total is inconsistent → hard fail.
        $this->expectException(\RuntimeException::class);
        new JofotaraBuilder()->build($this->ctx(116.0, 200.0));
    }

    public function test_fake_client_returns_istd_qr_on_accept(): void
    {
        $fake = new FakeJofotaraClient();
        $res = $fake->submit(['clientId' => 'c', 'secret' => 's', 'activityNumber' => 'a'], ['invoiceNumber' => 'INV-1', 'uuid' => 'u']);
        $this->assertSame('accepted', $res['status']);
        $this->assertStringStartsWith('ISTD-QR:', $res['qr']);
        $this->assertNotEmpty($res['registrationNumber']);
    }

    public function test_fake_client_rejection_and_outage(): void
    {
        $fake = new FakeJofotaraClient();
        $fake->rejectNext = true;
        $this->assertSame('rejected', $fake->submit([], ['invoiceNumber' => 'X'])['status']);

        $fake->unreachable = true;
        $this->expectException(\RuntimeException::class);
        $fake->submit([], ['invoiceNumber' => 'X']);
    }
}
