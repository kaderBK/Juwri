<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

/**
 * P1-08b follow-up: ZATCA invoice issue date/time (KSA-25, BR-KSA-70) and the QR timestamp (tag 3), pinned to KSA
 * local time (AST, Asia/Riyadh, UTC+3) whatever the server / app timezone.
 *
 * ZATCA Electronic Invoice XML Implementation Standard v1.2 (2023-05-19): "Times shall not include time zone
 * information if the time is local in the KSA. Alternatively, UTC time zone may be used by appending the suffix Z"
 * (hh:mm:ss = AST, hh:mm:ssZ = UTC); the QR timestamp matches the invoice issue date/time.
 * https://zatca.gov.sa/ar/E-Invoicing/SystemsDevelopers/Documents/20230519_ZATCA_Electronic_Invoice_XML_Implementation_Standard_%20vF.pdf
 * Both forms are allowed; AST without suffix is pinned so IssueDate is the KSA calendar day of the sale.
 */
final class KsaTime
{
    public const ZONE = 'Asia/Riyadh';

    public static function local(\DateTimeInterface $at): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone(self::ZONE));
    }

    /** cbc:IssueDate (KSA calendar day). */
    public static function date(\DateTimeInterface $at): string
    {
        return self::local($at)->format('Y-m-d');
    }

    /** cbc:IssueTime — hh:mm:ss, AST, no suffix. */
    public static function time(\DateTimeInterface $at): string
    {
        return self::local($at)->format('H:i:s');
    }

    /** QR tag 3 — the same instant as IssueDate + IssueTime: YYYY-MM-DDThh:mm:ss (AST, no suffix). */
    public static function qrTimestamp(\DateTimeInterface $at): string
    {
        return self::local($at)->format('Y-m-d\TH:i:s');
    }
}
