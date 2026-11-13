<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Juwri\Core\Contracts\Billing\AddonTax;
use Juwri\Core\Models\User;

/** P1-13 Community default: no platform plan, so no plan taxes on add-on prices. */
final class NoAddonTax implements AddonTax
{
    public function exclusiveTaxFor(User $user, float $price, ?string $currency = null): array
    {
        return ['tax' => 0.0, 'breakdown' => []];
    }
}
