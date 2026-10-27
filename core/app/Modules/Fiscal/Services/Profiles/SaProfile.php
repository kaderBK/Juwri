<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalProfile;
use Juwri\Modules\Fiscal\Services\Tlv;
use Juwri\Modules\Fiscal\Services\Zatca\KsaTime;

/**
 * Saudi Arabia — ZATCA (Fatoora) Phase 2. Simplified B2C receipts self-stamped
 * and reported within 24h; standard B2B invoices cleared before print. VAT 15%.
 * F0 ships the 5-tag pre-onboarding TLV QR; the Z track upgrades it to 9 tags
 * once a device is in production.
 */
class SaProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'SA';
    }

    public function countryName(): string
    {
        return 'Saudi Arabia';
    }

    public function defaultVatRate(): float
    {
        return 15.0;
    }

    public function currency(): string
    {
        return 'SAR';
    }

    public function driverMode(): string
    {
        return self::MODE_REPORT; // simplified default; standard invoices clear
    }

    public function hasLiveDriver(): bool
    {
        return true;
    }

    public function requiredReceiptFields(): array
    {
        return ['seller_name', 'vat_number', 'tax_breakdown', 'simplified_title', 'qr'];
    }

    /** KSA VAT number: 15 digits, starts and ends with 3. */
    public function validateVatNumber(?string $vat): bool
    {
        if ($vat === null || $vat === '') {
            return true;
        }

        return (bool) preg_match('/^3\d{13}3$/', $vat);
    }

    public function qr(FiscalReceiptContext $ctx): ?string
    {
        // If a live driver already produced a full TLV (9-tag), honour it.
        if ($full = $ctx->extra('tlv')) {
            return $full;
        }

        // 5-tag pre-onboarding fallback.
        return Tlv::encode([
            1 => $ctx->sellerName,
            2 => (string) ($ctx->vatNumber ?? ''),
            3 => KsaTime::qrTimestamp($ctx->issuedAt), // matches the invoice IssueDate/IssueTime (AST)
            4 => number_format($ctx->total, 2, '.', ''),
            5 => number_format($ctx->taxAmount, 2, '.', ''),
        ]);
    }
}
