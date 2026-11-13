<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Billing;

use Juwri\Core\Editions\Community\NoAddonTax;
use Juwri\Core\Models\User;

/**
 * P1-13 extension point: tax the platform adds on top of a paid add-on / app / theme price (taxes of the owner's
 * plan). Billing (ee) binds it; Community adds none ({@see NoAddonTax}).
 */
interface AddonTax
{
    /**
     * @return array{tax: float, breakdown: array<int, mixed>}
     */
    public function exclusiveTaxFor(User $user, float $price, ?string $currency = null): array;
}
