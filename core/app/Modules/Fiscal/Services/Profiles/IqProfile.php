<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * Iraq (GCT) — no VAT / e-invoicing regime; a 10% sales tax applies to
 * first-class venues only. Null-ish profile: R-P2 adds an OPTIONAL owner-enabled
 * "sales tax 10%" preset (via the existing tax config), never forced.
 */
class IqProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'IQ';
    }

    public function countryName(): string
    {
        return 'Iraq';
    }

    public function defaultVatRate(): float
    {
        return 0.0; // owner opts into 10% for first-class venues
    }

    public function currency(): string
    {
        return 'IQD';
    }

    public function requiredReceiptFields(): array
    {
        return [];
    }

    /** Optional preset: first-class venues may enable a 10% sales tax line. */
    public function suggestedTaxRate(): float
    {
        return 10.0;
    }
}
