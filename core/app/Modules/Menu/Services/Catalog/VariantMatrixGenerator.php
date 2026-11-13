<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Catalog;

use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;

/**
 * rev/multi-vertical Lane A2 — the cartesian generator behind the dashboard
 * "variant grid" editor (WORK-ITEMS A2). Given a set of axes:
 *
 *   ['Size' => ['S', 'M', 'L'], 'Color' => ['Red', 'Blue', 'Green']]
 *
 * it creates one `menu_variants` row per combination (a T-shirt with 3 sizes
 * × 3 colors → 9 rows), each carrying its combination as the existing
 * `options` JSON cell (`{"Size":"S","Color":"Red"}`) plus its own price/sku/
 * stock/barcode. Idempotent: re-running with the same (or an extended) axis
 * set never duplicates a combination that already exists — it only creates
 * the missing cells, so growing "Color" from 2 to 3 values just adds the new
 * column's rows instead of nuking manually-edited stock/sku on the rest.
 */
class VariantMatrixGenerator
{
    /**
     * @param  array<string, array<int, string>>  $axes  axis name => list of values. Empty/blank values are dropped.
     * @return array{created: list<MenuVariant>, skipped: int, axes: array<string, array<int,string>>}
     */
    public function generate(Menu $menu, array $axes, ?float $basePrice = null): array
    {
        $axes = $this->sanitizeAxes($axes);
        if (! $axes) {
            return ['created' => [], 'skipped' => 0, 'axes' => []];
        }

        $combinations = $this->cartesian($axes);

        /** @var array<string, true> $existingSignatures */
        $existingSignatures = [];
        foreach (MenuVariant::where('menu_id', $menu->id)->get() as $variant) {
            $options = $variant->options;
            $combo = is_object($options) ? get_object_vars($options) : [];
            if ($combo) {
                $existingSignatures[$this->signature($combo)] = true;
            }
        }

        $created = [];
        $skipped = 0;
        $position = (int) (MenuVariant::where('menu_id', $menu->id)->max('position') ?? 0);

        foreach ($combinations as $combo) {
            $signature = $this->signature($combo);
            if (isset($existingSignatures[$signature])) {
                $skipped++;

                continue;
            }

            $position += 1;
            $variant = MenuVariant::create([
                'menu_id' => $menu->id,
                'price' => $basePrice ?? (float) $menu->price,
                'options' => (object) $combo,
                'active' => true,
                'position' => $position,
            ]);
            $created[] = $variant;
            $existingSignatures[$signature] = true;
        }

        return ['created' => $created, 'skipped' => $skipped, 'axes' => $axes];
    }

    /**
     * @param  array<string, array<int, string>>  $axes
     * @return array<string, array<int, string>>
     */
    private function sanitizeAxes(array $axes): array
    {
        $clean = [];
        foreach ($axes as $name => $values) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $values = array_values(array_unique(array_filter(array_map(
                fn ($v) => trim((string) $v),
                (array) $values
            ), fn ($v) => $v !== '')));
            if ($values) {
                $clean[$name] = $values;
            }
        }

        return $clean;
    }

    /**
     * Cartesian product of every axis's values.
     *
     * @param  array<string, array<int, string>>  $axes
     * @return list<array<string, string>>
     */
    private function cartesian(array $axes): array
    {
        $result = [[]];
        foreach ($axes as $axisName => $values) {
            $next = [];
            foreach ($result as $combo) {
                foreach ($values as $value) {
                    $next[] = $combo + [$axisName => $value];
                }
            }
            $result = $next;
        }

        return $result;
    }

    /**
     * A stable string key for a combination, order-independent on axis name.
     *
     * @param  array<string, string>  $combo
     */
    private function signature(array $combo): string
    {
        ksort($combo);

        return json_encode($combo) ?: '';
    }
}
