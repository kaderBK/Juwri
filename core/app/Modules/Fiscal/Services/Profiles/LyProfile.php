<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Profiles;

use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * Libya — no VAT and no e-invoicing regime (J32: tax is the store's own responsibility; a stamp duty is an opt-in levy,
 * never forced). Null-ish profile so a Libyan store can name its country: before this, Libya was missing from the country
 * list, so a store in a launch country could not be set to it. Dinar (LYD) has three decimals.
 */
class LyProfile extends FiscalProfile
{
    public function country(): string
    {
        return 'LY';
    }

    public function countryName(): string
    {
        return 'Libya';
    }

    public function defaultVatRate(): float
    {
        return 0.0;
    }

    public function currency(): string
    {
        return 'LYD';
    }

    public function roundingScale(): int
    {
        return 3; // LYD is a 3-decimal currency
    }

    public function requiredReceiptFields(): array
    {
        return [];
    }
}
