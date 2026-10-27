<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * Bahrain (NBR) — VAT 10%. No e-invoicing mandate yet; digital receipts free to
 * issue (prior-approval requirement removed). Simplified tax invoice allowed at
 * or below BHD 500 or for non-VAT-registered buyers.
 */
class BhProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'BH';
    }

    public function countryName(): string
    {
        return 'Bahrain';
    }

    public function defaultVatRate(): float
    {
        return 10.0;
    }

    public function currency(): string
    {
        return 'BHD';
    }

    public function roundingScale(): int
    {
        return 3; // BHD is a 3-decimal currency
    }

    public function simplifiedThreshold(): ?float
    {
        return 500.0;
    }

    public function requiredReceiptFields(): array
    {
        return ['seller_name', 'vat_number', 'tax_breakdown', 'simplified_title'];
    }
}
