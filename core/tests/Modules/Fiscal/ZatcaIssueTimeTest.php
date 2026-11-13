<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\Profiles\SaProfile;
use Juwri\Modules\Fiscal\Services\Tlv;
use Juwri\Modules\Fiscal\Services\Zatca\UblBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\UblInvoice;
use Tests\TestCase;

/**
 * P1-08b follow-up (coordinator item 3): ZATCA invoice issue date/time (KSA-25, BR-KSA-70).
 *
 * ZATCA Electronic Invoice XML Implementation Standard (v1.2, 2023-05-19), §7 data types + BR-KSA-70:
 * "Times shall not include time zone information if the time is local in the KSA. Alternatively, UTC time zone may
 * be used by appending the suffix Z" — hh:mm:ss = AST (UTC+3), hh:mm:ssZ = UTC. The QR timestamp (tag 3) must match
 * the invoice issue date/time.
 * https://zatca.gov.sa/ar/E-Invoicing/SystemsDevelopers/Documents/20230519_ZATCA_Electronic_Invoice_XML_Implementation_Standard_%20vF.pdf
 *
 * The sale happened at 2026-10-02 22:30:15 UTC = 2026-10-03 01:30:15 in Riyadh (AST).
 */
class ZatcaIssueTimeTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
        parent::tearDown();
    }

    private function xml(\DateTimeInterface $at): string
    {
        return new UblBuilder()->build(new UblInvoice(
            type: 'simplified', number: 'SIM-1', uuid: 'u-1', issuedAt: $at, currency: 'SAR', sellerName: 'S',
            sellerVat: '300000000000003', sellerCr: null, icv: 1, previousHash: null,
            lines: [['name' => 'Order', 'qty' => 1.0, 'unit_price' => 100.0, 'tax_rate' => 15, 'line_total' => 100.0, 'tax_amount' => 15.0]],
            total: 115.0, taxTotal: 15.0,
        ));
    }

    /** @return array{0: string, 1: string} [IssueDate, IssueTime] */
    private function issue(string $xml): array
    {
        preg_match('#<cbc:IssueDate>([^<]+)</cbc:IssueDate>#', $xml, $d);
        preg_match('#<cbc:IssueTime>([^<]+)</cbc:IssueTime>#', $xml, $t);

        return [$d[1], $t[1]];
    }

    public function test_issue_date_and_time_are_riyadh_local_whatever_the_server_timezone(): void
    {
        foreach (['UTC', 'Asia/Kolkata', 'America/New_York'] as $serverTz) {
            date_default_timezone_set($serverTz);
            $at = new \DateTimeImmutable('2026-10-02 22:30:15', new \DateTimeZone('UTC'));
            $local = $at->setTimezone(new \DateTimeZone($serverTz)); // how the app hands it over (server time)

            // before: the server's wall clock with no suffix — UTC: 2026-10-02 / 22:30:15 (3 h early, wrong day);
            // Asia/Kolkata: 2026-10-03 / 04:00:15; America/New_York: 2026-10-02 / 18:30:15.
            $this->assertSame(['2026-10-03', '01:30:15'], $this->issue($this->xml($local)), $serverTz);
        }
    }

    public function test_the_receipt_qr_timestamp_matches_the_invoice(): void
    {
        $at = new \DateTimeImmutable('2026-10-02 22:30:15', new \DateTimeZone('UTC'));
        $ctx = new FiscalReceiptContext('S', '300000000000003', null, 115.0, 15.0, $at, 'SAR');
        $tags = Tlv::decode((string) new SaProfile()->qr($ctx));

        $this->assertSame('2026-10-03T01:30:15', $tags[3]); // before: '2026-10-02T22:30:15+00:00' (server offset)
    }
}
