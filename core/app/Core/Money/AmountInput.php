<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Money;


/**
 * Bug batch 9 · 4: a money amount typed into a form — the input steps by one minor unit of the currency it is in
 * (KWD 0.001, USD 0.01, JPY 1) and the server accepts exactly that precision (no more decimals than the currency's
 * exponent), so what is typed is what is stored and charged, never silently rounded.
 */
final class AmountInput
{
    /** The HTML `step` (and smallest `min`): one minor unit — "0.001", "0.01", "1". */
    public static function step(?string $currency): string
    {
        $dec = Currencies::exponent($currency ?: (string) config('settings.currency_code', 'USD'));

        return $dec > 0 ? '0.'.str_repeat('0', $dec - 1).'1' : '1';
    }

    /** Validation rule: at most the currency's decimals (an integer for a 0-decimal currency). */
    public static function precisionRule(?string $currency): string
    {
        $dec = Currencies::exponent($currency ?: (string) config('settings.currency_code', 'USD'));

        return $dec > 0 ? 'decimal:0,'.$dec : 'integer';
    }
}
