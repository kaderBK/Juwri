<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Jofotara;

/**
 * P1-08b follow-up: the JoFotara invoice issue date (cbc:IssueDate, yyyy-mm-dd — date only, ISTD e-invoicing
 * technical guide p.11, https://portal.jofotara.gov.jo/85e41d44095082ee4c9c.pdf) pinned to the Jordan calendar day of
 * the sale (Asia/Amman), whatever the server / app timezone. The guide sets the format, not a zone; the ISTD portal
 * defaults the date to "today" in Jordan, so a UTC day would date late-evening sales on the previous day.
 */
final class JordanTime
{
    public const ZONE = 'Asia/Amman';

    public static function date(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d');
    }
}
