<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * Qatar (GTA) — NO VAT; e-invoicing law still in draft (2026). Null profile,
 * 0%-ready: the receipt renders exactly as it does today (no fiscal block) so a
 * Qatar restaurant sees no change until a mandate lands.
 */
class QaProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'QA';
    }

    public function countryName(): string
    {
        return 'Qatar';
    }

    public function defaultVatRate(): float
    {
        return 0.0;
    }

    public function currency(): string
    {
        return 'QAR';
    }

    public function requiredReceiptFields(): array
    {
        return []; // null profile — no mandated fiscal block
    }
}
