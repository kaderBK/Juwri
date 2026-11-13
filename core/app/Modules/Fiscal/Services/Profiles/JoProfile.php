<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * Jordan — JoFotara (ISTD). Mandatory for all invoices since 1 Apr 2025. Strict
 * clear-before-print: the ISTD RETURNS the QR, so the customer copy is final
 * only after clearance. 16% general sales tax default (hospitality rates vary →
 * owner config wins).
 */
class JoProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'JO';
    }

    public function countryName(): string
    {
        return 'Jordan';
    }

    public function defaultVatRate(): float
    {
        return 16.0;
    }

    public function currency(): string
    {
        return 'JOD';
    }

    public function driverMode(): string
    {
        return self::MODE_CLEAR;
    }

    public function hasLiveDriver(): bool
    {
        return true;
    }

    public function roundingScale(): int
    {
        return 3; // JOD is a 3-decimal currency
    }

    public function requiredReceiptFields(): array
    {
        return ['seller_name', 'vat_number', 'tax_breakdown', 'qr'];
    }

    public function qr(FiscalReceiptContext $ctx): ?string
    {
        // JoFotara QR is returned by ISTD after clearance; render only then.
        return $ctx->extra('istd_qr');
    }
}
