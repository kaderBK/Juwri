<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Money\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Money\Money;
use LogicException;

/**
 * P1-05 (J25-4): the legacy major-unit attribute (e.g. `price`) of a money column stored as integer minor units
 * (`price_minor`). Writing stores minor units in the row's currency exponent. Reading gives the major amount in
 * the shape the old cast gave callers, views and JSON (API compatibility), chosen by the second parameter:
 *   - `float` (default)  — a number (old `float` cast / raw numeric column);
 *   - `store`            — a string at the currency's DISPLAY decimals ('10.00', '0.275'; old App\Casts\StoreMoney);
 *   - `decimal:N`        — a string with N decimals (old `decimal:N` cast);
 *   - `atleast:N`        — a string with N decimals, or the currency's exponent when it has more ('12.50' USD,
 *                          '1.235' KWD, '500.00' JPY) — an old `decimal:N` shape that never cuts a minor unit.
 * Registered by {@see HasMinorMoney}: `'price' => MinorMoney::class.':price_minor,store'`.
 *
 * @implements CastsAttributes<float|string|null, mixed>
 */
final class MinorMoney implements CastsAttributes
{
    public function __construct(private readonly string $minorColumn, private readonly string $format = 'float') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): float|string|null
    {
        $model = $this->owner($model);
        $currency = $model->minorMoneyCurrency();
        $pending = $model->pendingMinorMoney($key);
        if ($pending === null && $value !== null && $value !== '' && is_numeric($value)) {
            $pending = (string) $value; // a raw major key not settled yet (pivot attributes, see set())
        }
        if ($pending !== null) {
            $major = Money::decimal($pending)->toFloat();
        } else {
            $minor = $attributes[$this->minorColumn] ?? null;
            if ($minor === null || $minor === '') {
                return null;
            }
            $major = Money::majorFromMinor((int) $minor, $currency);
        }

        return self::present($major, $currency, $this->format);
    }

    /** The old cast's output shape for a major amount. */
    public static function present(float $major, string $currency, string $format): float|string
    {
        if ($format === 'store') {
            // Bug batch 9 · 2: an amount read back is exact — the currency's exponent, the precision the column holds
            // (display decimals dropped an IQD 13.833 to '13.83', which the wallet then charged; MGA 100.50 read '101').
            return number_format($major, Currencies::exponent($currency), '.', '');
        }
        if (str_starts_with($format, 'atleast:')) {
            return number_format($major, max((int) substr($format, 8), Currencies::exponent($currency)), '.', '');
        }
        if (str_starts_with($format, 'decimal:')) {
            return number_format($major, (int) substr($format, 8), '.', '');
        }

        return $major;
    }

    /** @return array<string, int|string|null> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $model = $this->owner($model);
        if ($value === null || $value === '') {
            $model->rememberMinorMoney($key, null);

            return [$this->minorColumn => null];
        }
        if ($value instanceof Money) {
            $value = $value->major();
        }
        if (! is_numeric($value)) {
            $value = (float) $value; // what the old decimal column did with "12abc" / junk: best effort, never throw
        }
        $major = (string) Money::decimal($value);
        $model->rememberMinorMoney($key, $major);
        $minor = [$this->minorColumn => Money::minorFromMajor($major, $model->minorMoneyCurrency())];

        // BelongsToMany::attach() casts pivot attributes on a throwaway pivot (fill()->getAttributes()) and saves
        // a NEW pivot from those raw attributes, so the exact amount must travel as a raw key; save() settles it.
        return $model instanceof Pivot ? $minor + [$key => $major] : $minor;
    }

    private function owner(Model $model): MinorMoneyModel
    {
        if (! $model instanceof MinorMoneyModel) {
            throw new LogicException($model::class.' must implement '.MinorMoneyModel::class.' (use '.HasMinorMoney::class.')');
        }

        return $model;
    }
}
