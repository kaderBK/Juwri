<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Illuminate\Support\Collection;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Models\BookableService;

/**
 * P3-11: what a booking costs, in one place — the services' prices, the store's tax added on top (the same rule as an
 * order: a percentage of the subtotal, or a fixed amount; `restaurant_tax_*` settings) and the deposit split. The
 * booking page, the headless quote and BookingService::book all read this, so the quoted total is the booked total.
 * Minor units of the services' currency throughout (KWD 3 decimals, JPY 0).
 */
final class BookingQuote
{
    /**
     * @param  Collection<int, BookableService>  $services
     * @return array{currency: string, subtotal_minor: int, tax_minor: int, total_minor: int, deposit_minor: int, due_later_minor: int, duration_min: int, lines: list<array{service_id: int, name: string, duration_min: int, price_minor: int, deposit_minor: int}>}
     */
    public static function for(int $storeId, Collection $services): array
    {
        $currency = (string) ($services->first()->currency ?? store_currency($storeId));
        $subtotal = (int) $services->sum('price_minor');
        $tax = self::tax($storeId, $subtotal, $currency);
        $total = $subtotal + $tax;
        $deposit = min($total, (int) $services->sum(fn (BookableService $s) => $s->depositMinor()));

        return [
            'currency' => $currency,
            'subtotal_minor' => $subtotal,
            'tax_minor' => $tax,
            'total_minor' => $total,
            'deposit_minor' => $deposit,
            'due_later_minor' => $total - $deposit,
            'duration_min' => (int) $services->sum('duration_min'),
            'lines' => $services->values()->map(fn (BookableService $s) => [
                'service_id' => (int) $s->id, 'name' => (string) $s->name, 'duration_min' => (int) $s->duration_min,
                'price_minor' => (int) $s->price_minor, 'deposit_minor' => (int) $s->depositMinor(),
            ])->all(),
        ];
    }

    /** The tax added on top of a subtotal (0 when the store has none): a percentage, or a fixed amount per booking. */
    public static function tax(int $storeId, int $subtotalMinor, string $currency): int
    {
        $charge = StoreSettings::get($storeId, 'restaurant_taxx_charge');
        if (! is_numeric($charge) || (float) $charge <= 0 || $subtotalMinor <= 0) {
            return 0;
        }
        $type = (string) (StoreSettings::get($storeId, 'restaurant_tax_type') ?: 'percentage');

        return $type === 'fixed'
            ? Money::minorFromMajor((float) $charge, $currency)
            : Money::ofMinor($subtotalMinor, $currency)->percent((float) $charge)->minor();
    }

    public static function taxLabel(int $storeId): string
    {
        $label = trim(StoreSettings::string($storeId, 'restaurant_tax_label'));
        $type = (string) (StoreSettings::get($storeId, 'restaurant_tax_type') ?: 'percentage');
        $rate = StoreSettings::get($storeId, 'restaurant_taxx_charge');
        $label = $label !== '' ? $label : ___('Tax');
        if ($type !== 'fixed' && is_numeric($rate) && (float) $rate > 0) {
            return $label.' '.rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.').'%';
        }

        return $label;
    }
}
