<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services;

use Juwri\Core\Contracts\Stores\CountryCurrency;

final class FiscalCountryCurrency implements CountryCurrency
{
    public function currencyFor(?string $country): ?string
    {
        $profile = FiscalRegistry::profile($country);

        return $profile !== null ? strtoupper($profile->currency()) : null;
    }
}
