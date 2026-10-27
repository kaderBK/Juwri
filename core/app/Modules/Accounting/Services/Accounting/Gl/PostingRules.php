<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting\Gl;

use Juwri\Core\Money\Money;

/**
 * SYSTEMS-RUN S0: the posting-rules registry. A rule key maps a money event to a
 * closure that returns the balanced GL lines (resolved against seeded system
 * account codes). Tracks A (sales/refunds/payments), P (payroll) and SUP (COGS)
 * register their rules here; S0 ships the registry plus the sales/refund STUBS
 * that A-P1 fleshes out into event listeners.
 *
 * A registered rule is: fn(int $storeId, array $ctx): array<array{account,debit,credit,memo}>
 */
class PostingRules
{
    /** @var array<string, callable> */
    protected static array $rules = [];

    public static function register(string $key, callable $resolver): void
    {
        static::$rules[$key] = $resolver;
    }

    public static function has(string $key): bool
    {
        return isset(static::$rules[$key]);
    }

    public static function keys(): array
    {
        return array_keys(static::$rules);
    }

    /** Resolve the balanced lines for a rule (does not post). */
    public static function resolve(string $key, int $storeId, array $ctx): array
    {
        if (! isset(static::$rules[$key])) {
            throw new \RuntimeException("No GL posting rule registered for '{$key}'.");
        }

        return (static::$rules[$key])($storeId, $ctx);
    }

    /**
     * P1-05: gross and tax rounded HALF_UP to the store currency's exponent (PHP-version independent), net = gross −
     * tax in exact minor units, as major floats for the journal lines.
     *
     * @param  array<string, mixed>  $ctx
     * @return array{0: float, 1: float, 2: float}
     */
    private static function grossTaxNet(int $storeId, array $ctx): array
    {
        $currency = store_currency($storeId);
        $gross = Money::minorFromMajor((float) ($ctx['gross'] ?? 0), $currency);
        $tax = Money::minorFromMajor((float) ($ctx['tax'] ?? 0), $currency);

        return [
            Money::majorFromMinor($gross, $currency),
            Money::majorFromMinor($tax, $currency),
            Money::majorFromMinor($gross - $tax, $currency),
        ];
    }

    /**
     * Boot the built-in stub rules. Idempotent. A-P1 replaces/extends these with
     * the real event wiring; they are here so the registry has a known contract
     * and S0's tests can post a sales journal end-to-end.
     */
    public static function bootDefaults(): void
    {
        if (static::has('sales')) {
            return;
        }

        // sales: DR Cash/clearing (gross), CR revenue (net), CR VAT payable (tax).
        static::register('sales', function (int $rid, array $ctx): array {
            [$gross, $tax, $net] = self::grossTaxNet($rid, $ctx);
            $cashCode = $ctx['cash_code'] ?? '1130'; // gateway clearing by default
            $lines = [
                ['account' => JournalEngine::account($rid, $cashCode), 'debit' => $gross, 'credit' => 0, 'memo' => 'Sale'],
                ['account' => JournalEngine::account($rid, '4100'), 'debit' => 0, 'credit' => $net, 'memo' => 'F&B sales'],
            ];
            if ($tax > 0) {
                $lines[] = ['account' => JournalEngine::account($rid, '2200'), 'debit' => 0, 'credit' => $tax, 'memo' => 'Output VAT'];
            }

            return $lines;
        });

        // refund: reverse of sales — DR revenue/VAT, CR cash.
        static::register('refund', function (int $rid, array $ctx): array {
            [$gross, $tax, $net] = self::grossTaxNet($rid, $ctx);
            $cashCode = $ctx['cash_code'] ?? '1130';
            $lines = [
                ['account' => JournalEngine::account($rid, '4900'), 'debit' => $net, 'credit' => 0, 'memo' => 'Refund'],
            ];
            if ($tax > 0) {
                $lines[] = ['account' => JournalEngine::account($rid, '2200'), 'debit' => $tax, 'credit' => 0, 'memo' => 'Reverse output VAT'];
            }
            $lines[] = ['account' => JournalEngine::account($rid, $cashCode), 'debit' => 0, 'credit' => $gross, 'memo' => 'Refund out'];

            return $lines;
        });
    }
}
