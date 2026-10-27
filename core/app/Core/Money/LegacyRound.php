<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Money;

/**
 * P1-00a: PHP-version-independent money rounding.
 *
 * PHP 8.4 dropped round()'s pre-rounding to 15 significant digits, so
 * round(0.035, 2) is 0.03 on 8.4 but 0.04 on 8.3 and in the storefront money core
 * (assets/store_templates/_shared/js/mb-money.js: toPrecision(15) then round half
 * up). Stored orders, the JS parity fixture and customers' receipts were all made
 * with the 8.3 rule, so money math keeps that rule explicitly: take the value's
 * 15 significant decimal digits and round half away from zero on those digits —
 * pure integer/string work, no float step that depends on the PHP version.
 *
 * P1-05: now a thin alias of Juwri\Core\Money\Money::round() (brick/money, RoundingMode::HalfUp on the
 * value's 15 significant digits — the same rule). Kept for existing callers.
 */
final class LegacyRound
{
    public static function round(float $value, int $decimals = 0): float
    {
        return Money::round($value, $decimals);
    }
}
