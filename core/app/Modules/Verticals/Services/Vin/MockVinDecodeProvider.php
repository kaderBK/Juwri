<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Vin;

use App\Models\VehicleFitment;

/**
 * Deterministic mock VIN decoder. Two strategies, in order:
 *
 *  1. If the store has VehicleFitment rows, hash the VIN to pick ONE of them —
 *     so a scanned VIN always resolves to a vehicle the store actually stocks
 *     parts for (clean demo + e2e: the resolved car has matching products).
 *  2. Otherwise fall back to a heuristic: VIN position 10 -> model year, the
 *     3-char WMI -> a plausible make, with a generic model/engine.
 *
 * Same VIN always yields the same vehicle (hash-based), so the UI is stable.
 */
class MockVinDecodeProvider implements VinDecodeProvider
{
    /** VIN position-10 model-year code -> year (2001-2030 slice, enough for a mock). */
    private const YEAR_CODES = [
        '1' => 2001, '2' => 2002, '3' => 2003, '4' => 2004, '5' => 2005, '6' => 2006,
        '7' => 2007, '8' => 2008, '9' => 2009, 'A' => 2010, 'B' => 2011, 'C' => 2012,
        'D' => 2013, 'E' => 2014, 'F' => 2015, 'G' => 2016, 'H' => 2017, 'J' => 2018,
        'K' => 2019, 'L' => 2020, 'M' => 2021, 'N' => 2022, 'P' => 2023, 'R' => 2024,
        'S' => 2025, 'T' => 2026, 'V' => 2027, 'W' => 2028, 'X' => 2029, 'Y' => 2030,
    ];

    /** First-char (region-ish) -> plausible make, for the heuristic fallback only. */
    private const WMI_MAKE = [
        '1' => 'Chevrolet', '4' => 'Ford', '5' => 'Ford', 'J' => 'Toyota',
        'K' => 'Hyundai', 'S' => 'BMW', 'W' => 'Volkswagen', 'Z' => 'Fiat',
    ];

    public function decode(string $vin, ?int $storeId = null): ?array
    {
        $vin = strtoupper(preg_replace('/[^A-HJ-NPR-Z0-9]/', '', $vin) ?? '');
        // A real VIN is 17 chars; be lenient for a mock but demand something usable.
        if (strlen($vin) < 8) {
            return null;
        }

        $hash = crc32($vin);

        // Strategy 1 — resolve to a vehicle the store stocks parts for.
        if ($storeId !== null) {
            $fitments = VehicleFitment::where('store_id', $storeId)
                ->get(['year', 'make', 'model', 'engine']);
            if ($fitments->isNotEmpty()) {
                $pick = $fitments[$hash % $fitments->count()];

                return [
                    'year' => (int) $pick->year,
                    'make' => (string) $pick->make,
                    'model' => (string) $pick->model,
                    'engine' => $pick->engine !== null ? (string) $pick->engine : null,
                ];
            }
        }

        // Strategy 2 — heuristic from VIN characters.
        $yearChar = $vin[9] ?? 'F';
        $year = self::YEAR_CODES[$yearChar] ?? (2015 + ($hash % 8));
        $make = self::WMI_MAKE[$vin[0]] ?? 'Toyota';

        return [
            'year' => $year,
            'make' => $make,
            'model' => 'Unknown model',
            'engine' => null,
        ];
    }
}
