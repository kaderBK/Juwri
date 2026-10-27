<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Tax;

use App\Models\Order;
use Juwri\Core\Money\Money;

/**
 * P6-259 / P6-260: paid orders grouped by VAT rate, in MINOR units of $currency. An order on the VAT engine (J44
 * VAT per line) splits by its own line rates; any other order sits at the store's percentage rate with the tax it
 * charged (the same tax the journal and fiscal invoice carry: Order::computeMoney()). Tips are out of scope.
 * Net + VAT of every bucket = order total − tip.
 */
final class TaxByRate
{
    /** The store's own percentage VAT rate; null for a fixed-amount tax (no rate). */
    public static function storeRate(int $storeId): ?float
    {
        $opts = store_options($storeId);
        if ((string) ($opts->restaurant_tax_type ?? 'percentage') === 'fixed') {
            return null;
        }

        return max(0.0, (float) ($opts->restaurant_taxx_charge ?? 0));
    }

    /**
     * @param  iterable<Order>  $orders
     * @return list<array{rate: float|null, category: string, net: int, vat: int}> highest rate first
     */
    public static function minor(iterable $orders, int $storeId, string $currency): array
    {
        $storeRate = self::storeRate($storeId);
        $decimals = money_decimals($currency);
        $buckets = [];
        $add = function (?float $rate, string $cat, int $net, int $tax) use (&$buckets): void {
            $key = ($rate === null ? 'x' : number_format($rate, 2, '.', '')).$cat;
            $buckets[$key] ??= ['rate' => $rate, 'category' => $cat, 'net' => 0, 'vat' => 0];
            $buckets[$key]['net'] += $net;
            $buckets[$key]['vat'] += $tax;
        };
        foreach ($orders as $order) {
            $vat = TaxEngine::compute($order);
            if ($vat !== null && $vat['decimals'] === $decimals) {
                foreach ($vat['lines'] as $l) {
                    if ($l['kind'] === 'tip') {
                        continue;
                    }
                    $cat = (string) $l['category'];
                    $add($cat === 'S' ? (float) $l['rate'] : 0.0, $cat, (int) $l['net'], (int) $l['tax']);
                }

                continue;
            }
            $tax = Money::minorFromMajor((float) $order->computeMoney()['tax'], $currency);
            $net = Money::minorFromMajor((float) $order->total, $currency) - $tax
                - Money::minorFromMajor((float) ($order->tip_amount ?? 0), $currency);
            $add($tax > 0 ? $storeRate : 0.0, $tax > 0 ? 'S' : 'O', $net, $tax);
        }
        $out = array_values($buckets);
        usort($out, fn ($a, $b) => [(float) $b['rate'], $a['category']] <=> [(float) $a['rate'], $b['category']]);

        return $out;
    }

    /**
     * Same as minor(), amounts as major floats.
     *
     * @param  iterable<Order>  $orders
     * @return list<array{rate: float|null, category: string, net: float, vat: float}>
     */
    public static function major(iterable $orders, int $storeId, string $currency): array
    {
        return array_map(fn ($b) => ['rate' => $b['rate'], 'category' => $b['category'],
            'net' => Money::majorFromMinor($b['net'], $currency), 'vat' => Money::majorFromMinor($b['vat'], $currency)],
            self::minor($orders, $storeId, $currency));
    }
}
