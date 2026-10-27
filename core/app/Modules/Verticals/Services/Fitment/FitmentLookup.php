<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Fitment;

use App\Models\VehicleFitment;
use App\Services\Verticals\VerticalRegistry;
use Juwri\Core\Models\Store;
use Juwri\Modules\Verticals\Contracts\VehicleFitments;

/** P3-08b: {@see VehicleFitments} over the `vehicle_fitments` table. */
final class FitmentLookup implements VehicleFitments
{
    public function __construct(private VerticalRegistry $verticals) {}

    public function enabled(Store $store): bool
    {
        return $this->verticals->has((string) $store->store_type, 'fitment');
    }

    public function resolve(Store $store, string $key): ?array
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 160 || ! $this->enabled($store)) {
            return null;
        }
        if (stripos($key, 'vin:') === 0) {
            $vin = strtoupper((string) preg_replace('/[\s\-]/', '', substr($key, 4)));

            return preg_match('/^[A-HJ-NPR-Z0-9]{8,17}$/', $vin) === 1 ? ['label' => $vin, 'vin' => $vin] : null;
        }
        $parts = array_map(fn (string $p) => trim((string) preg_replace('/\s+/u', ' ', $p)), explode('|', $key));
        if (count($parts) < 3 || count($parts) > 5 || preg_match('/^\d{4}$/', $parts[0]) !== 1 || $parts[1] === '' || $parts[2] === '') {
            return null;
        }
        $year = (int) $parts[0];
        if ($year < 1900 || $year > 2100) {
            return null;
        }
        $vehicle = ['label' => implode(' ', array_filter([$parts[0], $parts[1], $parts[2], $parts[3] ?? ''])), 'year' => $year, 'make' => $parts[1], 'model' => $parts[2]];
        if (($parts[3] ?? '') !== '') {
            $vehicle['submodel'] = $parts[3];
        }
        if (($parts[4] ?? '') !== '') {
            $vehicle['engine'] = $parts[4];
        }
        foreach (['make', 'model', 'submodel', 'engine'] as $field) {
            if (isset($vehicle[$field]) && mb_strlen($vehicle[$field]) > 100) {
                return null;
            }
        }

        return $vehicle;
    }

    public function fits(Store $store, array $menuIds, array $vehicle): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $menuIds))), 500) as $ids) {
            $rows = VehicleFitment::query()->where('store_id', $store->id)->whereIn('menu_id', $ids)
                ->get(['menu_id', 'year', 'make', 'model', 'submodel', 'engine', 'vin_prefix']);
            foreach ($ids as $id) {
                $out[$id] = null;
            }
            foreach ($rows as $row) {
                $id = (int) $row->menu_id;
                $out[$id] = ($out[$id] ?? false) || $this->matches($row, $vehicle);
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $v */
    private function matches(VehicleFitment $row, array $v): bool
    {
        if (isset($v['vin'])) {
            $prefix = strtoupper((string) $row->vin_prefix);

            return $prefix !== '' && str_starts_with((string) $v['vin'], $prefix);
        }
        if ((int) $row->year !== (int) $v['year'] || ! self::same((string) $row->make, (string) $v['make']) || ! self::same((string) $row->model, (string) $v['model'])) {
            return false;
        }
        // A row without a submodel / engine fits every one. A shopper who named one gets only the rows that agree; one who
        // did not (year / make / model only) still gets the rows that name an engine: nothing says it does not fit.
        foreach (['submodel', 'engine'] as $field) {
            $own = trim((string) $row->{$field});
            $given = trim((string) ($v[$field] ?? ''));
            if ($own !== '' && $given !== '' && ! self::same($own, $given)) {
                return false;
            }
        }
        // The VIN of a row only narrows a vehicle picked by VIN; a year / make / model pick has none to compare.
        return true;
    }

    private static function same(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
