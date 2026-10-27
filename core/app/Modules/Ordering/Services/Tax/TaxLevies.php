<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Tax;

/**
 * J32 / P6-116: pre-VAT levies — a store's own charges such as a municipality or tourism fee, a table tax or a stamp
 * duty. Each levy is a percentage of a base (or a fixed amount), applied after the discount and before VAT (VAT, when
 * the store charges it, is due on the levy too) and shown as its own line on the receipt, the invoice and the journal.
 *
 * Every levy is the store's choice: the presets are suggestions, all OFF, all editable (rates are not advice).
 * Stored as the `tax_levies` store setting: list of
 * {id, name, type: percent|fixed, value, base: items|total, over, value_over, enabled}.
 * - base `items`: the items after discount, before VAT; `total`: items + delivery/tray/service charge, same.
 * - over / value_over (percent only): when the base is above `over` (major units), `value_over` applies instead.
 *
 * @phpstan-type Levy array{id: string, name: string, type: string, value: float, base: string, over: float|null, value_over: float|null, enabled: bool}
 */
final class TaxLevies
{
    public const MAX = 10;

    /**
     * Suggested levies (OFF). Values are research notes, not advice (see the tax-rules research notes kept with the project docs).
     *
     * @var array<string, array{country: string, name: string, type: string, value: float, base: string, over: float|null, value_over: float|null}>
     */
    public const PRESETS = [
        'ae_municipality' => ['country' => 'AE', 'name' => 'Municipality fee', 'type' => 'percent', 'value' => 7.0, 'base' => 'items', 'over' => null, 'value_over' => null],
        'ae_tourism' => ['country' => 'AE', 'name' => 'Tourism fee', 'type' => 'percent', 'value' => 6.0, 'base' => 'items', 'over' => null, 'value_over' => null],
        'eg_table' => ['country' => 'EG', 'name' => 'Table tax', 'type' => 'percent', 'value' => 5.0, 'base' => 'items', 'over' => null, 'value_over' => null],
        'ly_stamp' => ['country' => 'LY', 'name' => 'Stamp duty', 'type' => 'percent', 'value' => 0.5, 'base' => 'total', 'over' => 100.0, 'value_over' => 1.0],
    ];

    /**
     * The store's levies, cleaned (all of them, enabled or not).
     *
     * @return list<Levy>
     */
    public static function all(?object $opts): array
    {
        return self::normalize($opts->tax_levies ?? null);
    }

    /**
     * The store's enabled levies.
     *
     * @return list<Levy>
     */
    public static function enabled(?object $opts): array
    {
        return array_values(array_filter(self::all($opts), fn (array $l): bool => $l['enabled']));
    }

    /**
     * What the settings form shows: the saved levies, then every preset not saved yet (OFF).
     *
     * @return list<Levy>
     */
    public static function forForm(?object $opts): array
    {
        $rows = self::all($opts);
        $ids = array_column($rows, 'id');
        foreach (self::PRESETS as $id => $p) {
            if (! in_array($id, $ids, true)) {
                $rows[] = ['id' => $id, 'name' => $p['name'], 'type' => $p['type'], 'value' => $p['value'], 'base' => $p['base'],
                    'over' => $p['over'], 'value_over' => $p['value_over'], 'enabled' => false];
            }
        }

        return $rows;
    }

    /**
     * Clean raw input (form rows, stored JSON) into levies. Rows without a name are dropped.
     *
     * @return list<Levy>
     */
    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_validate($raw) ? json_decode($raw, true) : [];
        } elseif (is_object($raw) || (is_array($raw) && array_filter($raw, 'is_object') !== [])) {
            $raw = json_decode((string) json_encode($raw), true); // store_options() hands JSON settings back as objects
        }
        if (! is_array($raw)) {
            return [];
        }
        $num = fn ($v): ?float => is_numeric($v) && (float) $v >= 0 ? (float) $v : null;
        $out = [];
        $seen = [];
        foreach (array_values($raw) as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim(mb_substr(strip_tags((string) ($row['name'] ?? '')), 0, 60));
            if ($name === '') {
                continue;
            }
            $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($row['id'] ?? ''))) ?: 'custom_'.$i;
            if (isset($seen[$id])) {
                $id .= '_'.$i;
            }
            $seen[$id] = true;
            $type = ($row['type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
            $value = $num($row['value'] ?? null) ?? 0.0;
            $over = $type === 'percent' ? $num($row['over'] ?? null) : null;
            $valueOver = $over !== null ? $num($row['value_over'] ?? null) : null;
            if ($type === 'percent') {
                $value = min(100.0, $value);
                $valueOver = $valueOver !== null ? min(100.0, $valueOver) : null;
            }
            $out[] = [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'value' => $value,
                'base' => ($row['base'] ?? 'items') === 'total' ? 'total' : 'items',
                'over' => $valueOver !== null ? $over : null,
                'value_over' => $valueOver,
                'enabled' => filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
            if (count($out) >= self::MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * One levy's amount in minor units on a base in minor units.
     *
     * @param  Levy  $levy
     */
    public static function amount(array $levy, int $baseMinor, int $decimals): int
    {
        $f = 10 ** $decimals;
        if ($levy['type'] === 'fixed') {
            return (int) round(round($levy['value'] * $f, 6));
        }
        if ($baseMinor <= 0) {
            return 0;
        }
        $pct = $levy['value'];
        if ($levy['over'] !== null && $levy['value_over'] !== null && $baseMinor > (int) round($levy['over'] * $f)) {
            $pct = $levy['value_over'];
        }

        return (int) round(round($baseMinor * $pct / 100, 6));
    }
}
