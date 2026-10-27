<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * Kuwait (MoF) — no VAT or e-invoicing mandate published. Null profile: receipts
 * unchanged from today.
 */
class KwProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'KW';
    }

    public function countryName(): string
    {
        return 'Kuwait';
    }

    public function defaultVatRate(): float
    {
        return 0.0;
    }

    public function currency(): string
    {
        return 'KWD';
    }

    public function roundingScale(): int
    {
        return 3; // KWD is a 3-decimal currency
    }

    public function requiredReceiptFields(): array
    {
        return [];
    }
}
