<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * Algeria (DGI) — nothing mandated or published for e-invoicing. Null profile:
 * receipts unchanged from today. VAT 19% exists but stays owner-configured.
 */
class DzProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'DZ';
    }

    public function countryName(): string
    {
        return 'Algeria';
    }

    public function defaultVatRate(): float
    {
        return 0.0;
    }

    public function currency(): string
    {
        return 'DZD';
    }

    public function requiredReceiptFields(): array
    {
        return [];
    }
}
