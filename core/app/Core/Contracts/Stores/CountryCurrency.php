<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Stores;

/** P6-108b: the currency of a supported store country (bound by the Fiscal module, which owns the country profiles). */
interface CountryCurrency
{
    public function currencyFor(?string $country): ?string;
}
