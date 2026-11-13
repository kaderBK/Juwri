<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Pricing;

use InvalidArgumentException;

/**
 * The till's price calculator (the offline POS spec §4.1): ONE pricing rule, implemented twice — here
 * and in assets/pos/till-calculator.js — and held identical by the golden vectors in tests/Fixtures/till-calculator
 * (PHPUnit runs this side, `node --test tests/js/pos` the other; CI fails on any difference).
 *
 * Pure and deterministic, integers only (minor units of the store currency, no floats anywhere). The rule is the
 * server's money rule for a till sale (Order::computeMoney): a line is (unit + extras + choice options) × quantity; the
 * store tax is exclusive and charged on the item subtotal AFTER the discount (P6-271; `tax_base` 'gross' = the old
 * pre-discount rule) — a percentage (half-up to the minor
 * unit) or a fixed amount, nothing when the subtotal is 0; a manual discount (an amount, or a percentage of the
 * subtotal, half-up) never exceeds the subtotal; total = subtotal + tax + service charge + tip − discount, never below 0.
 *
 * Input (all amounts in minor units, non-negative integers):
 *   lines: [{unit_minor, qty (1..9999), extras_minor: int[], options_minor: int[]}]
 *   tax: {mode: none} | {mode: percentage, rate: "15" | "12.5" (≤ 4 decimals, 0..100)} | {mode: fixed, amount_minor}
 *   discount: {mode: none} | {mode: amount, amount_minor} | {mode: percent, rate: "10" (≤ 4 decimals, 0..100)}
 *   service_minor, tip_minor
 * Output: {lines: [{line_minor}], subtotal_minor, discount_minor, tax_minor, service_minor, tip_minor, total_minor}
 */
final class TillCalculator
{
    /** Amounts above this are refused, so every product stays exact in 64-bit integers (and in JS BigInt / Number). */
    public const MAX_MINOR = 100_000_000_000; // 1e11 minor units

    private const RATE_SCALE = 10_000; // rates carry up to 4 decimals

    /**
     * @param  array<string, mixed>  $in
     * @return array{lines: list<array{line_minor: int}>, subtotal_minor: int, discount_minor: int, tax_minor: int, service_minor: int, tip_minor: int, total_minor: int}
     */
    public static function price(array $in): array
    {
        $lines = [];
        $subtotal = 0;
        foreach ((array) ($in['lines'] ?? []) as $i => $line) {
            if (! is_array($line)) {
                throw new InvalidArgumentException("line $i is not an object");
            }
            $qty = self::int($line['qty'] ?? null, "lines[$i].qty", 1, 9999);
            $unit = self::amount($line['unit_minor'] ?? null, "lines[$i].unit_minor");
            foreach (['extras_minor', 'options_minor'] as $key) {
                foreach ((array) ($line[$key] ?? []) as $j => $add) {
                    $unit += self::amount($add, "lines[$i].{$key}[$j]");
                }
            }
            $amount = self::cap(self::cap($unit, "lines[$i].unit") * $qty, "lines[$i]");
            $lines[] = ['line_minor' => $amount];
            $subtotal = self::cap($subtotal + $amount, 'subtotal');
        }

        $discount = self::discount((array) ($in['discount'] ?? ['mode' => 'none']), $subtotal);
        // P6-271: tax on the subtotal after the discount; 'tax_base' => 'gross' = the pre-P6-271 rule (a sale queued
        // offline by a till that still ran the old calculator)
        $tax = self::tax((array) ($in['tax'] ?? ['mode' => 'none']), ($in['tax_base'] ?? 'net') === 'gross' ? $subtotal : $subtotal - $discount);
        $service = self::amount($in['service_minor'] ?? 0, 'service_minor');
        $tip = self::amount($in['tip_minor'] ?? 0, 'tip_minor');

        return [
            'lines' => $lines,
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'tax_minor' => $tax,
            'service_minor' => $service,
            'tip_minor' => $tip,
            'total_minor' => max(0, $subtotal + $tax + $service + $tip - $discount),
        ];
    }

    /** @param  array<string, mixed>  $tax */
    private static function tax(array $tax, int $subtotal): int
    {
        return match ($tax['mode'] ?? 'none') {
            'none' => 0,
            'percentage' => $subtotal > 0 ? self::percentOf($subtotal, self::rate($tax['rate'] ?? null, 'tax.rate')) : 0,
            'fixed' => $subtotal > 0 ? self::amount($tax['amount_minor'] ?? null, 'tax.amount_minor') : 0,
            default => throw new InvalidArgumentException('tax.mode must be none, percentage or fixed'),
        };
    }

    /** @param  array<string, mixed>  $discount */
    private static function discount(array $discount, int $subtotal): int
    {
        $amount = match ($discount['mode'] ?? 'none') {
            'none' => 0,
            'amount' => self::amount($discount['amount_minor'] ?? null, 'discount.amount_minor'),
            'percent' => self::percentOf($subtotal, self::rate($discount['rate'] ?? null, 'discount.rate')),
            default => throw new InvalidArgumentException('discount.mode must be none, amount or percent'),
        };

        return min($amount, $subtotal);
    }

    /** round_half_up(amount × rate / 100), rate scaled by 10^4 — exact integer arithmetic. */
    private static function percentOf(int $amount, int $scaledRate): int
    {
        $den = 100 * self::RATE_SCALE;
        $num = $amount * $scaledRate;

        return intdiv($num * 2 + $den, 2 * $den);
    }

    /** "15", "12.5", "0.0825" → 150000, 125000, 825 (×10^4); 0..100 with at most 4 decimals. */
    private static function rate(mixed $rate, string $field): int
    {
        $s = is_int($rate) ? (string) $rate : (is_string($rate) ? $rate : '');
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/D', $s, $m) !== 1) {
            throw new InvalidArgumentException("$field must be a decimal string with at most 4 decimals");
        }
        $scaled = (int) $m[1] * self::RATE_SCALE + (int) str_pad($m[2] ?? '', 4, '0');
        if ($scaled > 100 * self::RATE_SCALE) {
            throw new InvalidArgumentException("$field must be between 0 and 100");
        }

        return $scaled;
    }

    private static function amount(mixed $v, string $field): int
    {
        return self::int($v, $field, 0, self::MAX_MINOR);
    }

    private static function int(mixed $v, string $field, int $min, int $max): int
    {
        if (! is_int($v) || $v < $min || $v > $max) {
            throw new InvalidArgumentException("$field must be an integer from $min to $max");
        }

        return $v;
    }

    private static function cap(int $v, string $field): int
    {
        if ($v > self::MAX_MINOR) {
            throw new InvalidArgumentException("$field is above the maximum amount");
        }

        return $v;
    }
}
