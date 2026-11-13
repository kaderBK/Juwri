<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * UAE (FTA) — VAT 5%. B2C excluded from the e-invoicing mandate; B2B/B2G via
 * Accredited Service Providers (Peppol PINT-AE) from 2027. Simplified tax
 * invoice allowed at or below AED 10,000. No QR mandated. R-P1 wires the
 * threshold auto-switch + PINT-AE export seam.
 */
class AeProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'AE';
    }

    public function countryName(): string
    {
        return 'United Arab Emirates';
    }

    public function defaultVatRate(): float
    {
        return 5.0;
    }

    public function currency(): string
    {
        return 'AED';
    }

    public function simplifiedThreshold(): ?float
    {
        return 10000.0;
    }

    public function requiredReceiptFields(): array
    {
        return ['seller_name', 'vat_number', 'tax_breakdown', 'simplified_title'];
    }

    /** UAE TRN: 15 digits. */
    public function validateVatNumber(?string $vat): bool
    {
        if ($vat === null || $vat === '') {
            return true;
        }

        return (bool) preg_match('/^\d{15}$/', $vat);
    }
}
