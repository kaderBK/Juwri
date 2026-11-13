<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * Morocco (DGI) — B2B CTC pre-clearance from 2026 (large taxpayers first), UBL
 * 2.1 named but specs NOT yet published; B2C deferred. R-P2 wires a UBL-mappable
 * data seam only (specs-pending ledger row). VAT 20%.
 */
class MaProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'MA';
    }

    public function countryName(): string
    {
        return 'Morocco';
    }

    public function defaultVatRate(): float
    {
        return 20.0;
    }

    public function currency(): string
    {
        return 'MAD';
    }

    public function requiredReceiptFields(): array
    {
        return ['seller_name', 'vat_number', 'tax_breakdown'];
    }
}
