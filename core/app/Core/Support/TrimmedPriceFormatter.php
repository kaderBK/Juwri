<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * Storefront price callable (`$formatPrice($amount)`): rounds to the currency's decimals and drops them when the
 * amount is whole ("$16" not "$16.00"). An object instead of a closure in the view: see ViewHelpers.
 */
final class TrimmedPriceFormatter
{
    public function __construct(private mixed $sign, private mixed $position, private mixed $decimals) {}

    public function __invoke(mixed $amount): string
    {
        $n = round((float) $amount, $this->decimals);
        $str = ($n == (int) $n) ? number_format($n, 0) : number_format($n, $this->decimals);

        return $this->position === '1' ? $this->sign.$str : $str.$this->sign;
    }
}
