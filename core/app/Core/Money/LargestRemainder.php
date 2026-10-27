<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Money;

/**
 * P6-126: largest-remainder split of an integer amount of minor units — shared by the tax engine (Ordering) and the
 * fiscal document math (Fiscal), so neither reaches into the other's internals. Moved verbatim from
 * FiscalInvoiceMath::allocate (which now delegates here); the parts always sum to $total.
 */
final class LargestRemainder
{
    /**
     * @param  array<int,int>  $weights  non-negative
     * @return array<int,int>
     */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0 || $weights === []) {
            return array_map(fn () => 0, $weights);
        }
        $parts = [];
        $rema = [];
        foreach ($weights as $k => $w) {
            $exact = $total * $w / $sum;
            $parts[$k] = (int) floor($exact);
            $rema[$k] = $exact - $parts[$k];
        }
        $left = $total - array_sum($parts);
        arsort($rema);
        foreach (array_keys($rema) as $k) {
            if ($left <= 0) {
                break;
            }
            $parts[$k]++;
            $left--;
        }

        return $parts;
    }
}
