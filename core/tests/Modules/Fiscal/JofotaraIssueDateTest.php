<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraBuilder;
use Tests\TestCase;

/**
 * P1-08b follow-up (coordinator): JoFotara invoice issue date.
 *
 * ISTD e-invoicing technical guide (JoFotara portal, https://portal.jofotara.gov.jo/85e41d44095082ee4c9c.pdf ,
 * cited as v1.4 2023-12-01, p.11): cbc:IssueDate is a DATE only, format yyyy-mm-dd — no issue time and no time-zone
 * rule; the ISTD portal itself defaults the issue date to "today". Pinned: the Jordan calendar day of the sale
 * (Asia/Amman, UTC+3), whatever the server timezone — a UTC server would otherwise date a sale made after 21:00 UTC
 * (midnight in Amman) on the previous day.
 *
 * The sale happened at 2026-10-02 22:30:15 UTC = 2026-10-03 01:30:15 in Amman.
 */
class JofotaraIssueDateTest extends TestCase
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

    public function test_issue_date_is_the_amman_day_whatever_the_server_timezone(): void
    {
        foreach (['UTC', 'Asia/Kolkata', 'America/New_York'] as $serverTz) {
            date_default_timezone_set($serverTz);
            $at = new \DateTimeImmutable('2026-10-02 22:30:15', new \DateTimeZone('UTC'));
            $local = $at->setTimezone(new \DateTimeZone($serverTz)); // how the app hands it over (server time)
            $ctx = new FiscalReceiptContext('Amman Grill', '12345678', null, 11.6, 1.6, $local, 'JOD');
            $built = new JofotaraBuilder()->build($ctx, ['number' => 'INV-1', 'uuid' => 'u-1']);

            preg_match('#<cbc:IssueDate>([^<]+)</cbc:IssueDate>#', $built['ubl'], $m);
            // before: the server's calendar day — UTC 2026-10-02, Asia/Kolkata 2026-10-03, America/New_York 2026-10-02.
            $this->assertSame(['2026-10-03', '2026-10-03'], [$m[1], $built['json']['issueDate']], $serverTz);
        }
    }
}
