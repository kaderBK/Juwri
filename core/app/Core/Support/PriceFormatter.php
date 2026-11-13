<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * Callable price formatter for storefront account/track views (`$formatPrice($amount)`). An object instead of a closure
 * in the view: see ViewHelpers for why compiled views must not hold closure literals.
 * Always shows the currency's own decimal places (a receipt column must not read "$16" next to "$1.50").
 */
final class PriceFormatter
{
    public function __construct(private string $sign, private string $position, private int $decimals) {}

    public function __invoke(mixed $amount): string
    {
        $str = number_format(round((float) $amount, $this->decimals), $this->decimals);

        return $this->position === '1' ? $this->sign.$str : $str.$this->sign;
    }
}
