<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support\Money;

use App\Models\Coupon;
use Juwri\Core\Money\Currencies;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * The discount line of a transaction's coupon snapshot (`transactions.coupon`), for the admin/owner invoice and
 * details views and the invoice PDF. A percentage coupon reads "12.5%"; a fixed coupon reads its amount in ITS OWN
 * currency with that currency's decimals ("4.99 USD", "1.125 KWD", "500 JPY") — the views used to print a "$".
 * The coupon currency: the snapshot's `currency_code` (written at checkout), else the coupon row's, else the
 * transaction's. free_delivery / bogo carry no plan discount (CouponService gives 0) — before, the views
 * subtracted their stored number as if it were an amount.
 */
final class TransactionCouponLine
{
    /** @return array{code: string, label: string, discount: float}|null */
    public static function for(Transaction $transaction): ?array
    {
        $snapshot = $transaction->coupon;
        if (! is_object($snapshot)) {
            return null;
        }
        $type = (string) ($snapshot->coupon_type ?? 'percentage');
        $value = (float) ($snapshot->percentage ?? 0);
        $base = (float) ($transaction->base_amount ?? $transaction->amount ?? 0);
        $number = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        if ($type === 'percentage') {
            return ['code' => (string) ($snapshot->code ?? ''), 'label' => $number.'%', 'discount' => $base * $value / 100];
        }
        if ($type === 'fixed') {
            $currency = self::currency($transaction, $snapshot);

            return [
                'code' => (string) ($snapshot->code ?? ''),
                'label' => number_format($value, Currencies::exponent($currency), '.', '').' '.$currency,
                'discount' => min($base, $value),
            ];
        }

        return ['code' => (string) ($snapshot->code ?? ''), 'label' => '', 'discount' => 0.0];
    }

    private static function currency(Transaction $transaction, object $snapshot): string
    {
        $code = (string) ($snapshot->currency_code ?? '');
        if ($code === '' && ! empty($snapshot->id)) {
            $coupon = Coupon::find($snapshot->id);
            $code = $coupon ? $coupon->minorMoneyCurrency() : '';
        }
        if ($code === '') {
            $code = (string) ($transaction->currency_code ?: platform_currency());
        }

        return strtoupper($code);
    }
}
