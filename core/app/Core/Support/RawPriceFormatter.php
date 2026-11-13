<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * Storefront price callable (`$formatPrice($amount)`): the amount to the currency's decimals with no thousands
 * separator, sign before or after by position ('1' = before). An object instead of a closure in the view: see ViewHelpers.
 */
final class RawPriceFormatter
{
    public function __construct(private mixed $sign, private mixed $position, private mixed $decimals) {}

    public function __invoke(mixed $amount): string
    {
        $str = number_format((float) $amount, $this->decimals, '.', '');

        return $this->position === '1' ? $this->sign.$str : $str.$this->sign;
    }
}
