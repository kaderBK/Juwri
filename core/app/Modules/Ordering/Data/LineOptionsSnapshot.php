<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Data;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Money\Money;

/**
 * P1-06 (money, J25-4): the `order_items.options` snapshot — choice-group selections and combo components:
 *
 *   [{group_id, title, selected: [{option, price_minor, …}]}, …]   (combo lines add `combo`, `menu_id`, `variant_id`)
 *
 * Stored with each price as `price_minor` (integer, in the line's currency). Read with the legacy major-unit
 * `price` (float) added next to it, so every renderer, total and API client that reads `$opt['price']` keeps
 * working. A snapshot written before this change (only `price`) reads unchanged. Writes may carry `price`
 * (legacy writers, or a read-modify-write); {@see self::toStorage()} turns it into `price_minor` once the line's
 * currency is known (on save).
 *
 * @implements CastsAttributes<array<mixed>|null, mixed>
 */
final class LineOptionsSnapshot implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        $data = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($data)) {
            return $data;
        }

        $currency = null;
        $resolve = function () use ($model, &$currency): string {
            return $currency ??= $model instanceof MinorMoneyModel ? $model->minorMoneyCurrency() : 'USD';
        };

        return self::mapSelected($data, function (array $opt) use ($resolve): array {
            if (isset($opt['price_minor']) && is_numeric($opt['price_minor']) && ! array_key_exists('price', $opt)) {
                $opt['price'] = Money::ofMinor((int) $opt['price_minor'], $resolve())->toFloat();
            }

            return $opt;
        });
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return [$key => $value === null ? null : json_encode($value)];
    }

    /**
     * The snapshot as stored: every `price` (major) becomes `price_minor` in $currency; entries already in minor
     * units are kept.
     *
     * @param  array<mixed>  $groups
     * @return array<mixed>
     */
    public static function toStorage(array $groups, string $currency): array
    {
        return self::mapSelected($groups, function (array $opt) use ($currency): array {
            if (array_key_exists('price', $opt)) {
                $price = $opt['price'];
                unset($opt['price']);
                $opt['price_minor'] = ($price === null || $price === '' || ! is_numeric($price))
                    ? 0
                    : Money::minorFromMajor($price, $currency);
            }

            return $opt;
        });
    }

    /**
     * Apply $fn to every selected option array of every group.
     *
     * @param  array<mixed>  $groups
     * @param  callable(array<mixed>): array<mixed>  $fn
     * @return array<mixed>
     */
    private static function mapSelected(array $groups, callable $fn): array
    {
        foreach ($groups as $g => $group) {
            if (! is_array($group) || ! isset($group['selected']) || ! is_array($group['selected'])) {
                continue;
            }
            foreach ($group['selected'] as $i => $opt) {
                if (is_array($opt)) {
                    $groups[$g]['selected'][$i] = $fn($opt);
                }
            }
        }

        return $groups;
    }
}
