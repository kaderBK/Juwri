<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * Tunisia (TTN El Fatoora) — B2C voluntary; B2B/B2G mandatory for large + all
 * service providers from 2026 (hospitality named). TEIF XML via TTN, ANCE
 * qualified signature, signed QR (CEV). R-P2 wires the TEIF export seam + ANCE
 * signing stub; TTN commercial subscription is a deferred-ledger item. VAT 19%.
 */
class TnProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'TN';
    }

    public function countryName(): string
    {
        return 'Tunisia';
    }

    public function defaultVatRate(): float
    {
        return 19.0;
    }

    public function currency(): string
    {
        return 'TND';
    }

    public function roundingScale(): int
    {
        return 3; // TND is a 3-decimal currency
    }

    public function requiredReceiptFields(): array
    {
        return ['seller_name', 'vat_number', 'tax_breakdown'];
    }
}
