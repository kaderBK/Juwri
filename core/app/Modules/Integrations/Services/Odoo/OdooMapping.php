<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Odoo;

use Juwri\Core\Settings\StoreSettings;

/**
 * P4-21 follow-up: the per-item Odoo mapping of a store — store setting `odoo_map` (JSON, non-secret):
 *
 *   tax         `store` (the store's own tax) and `untaxed` (a 0 % Odoo tax for delivery, tip, service lines) → Odoo tax id
 *   payment     a Juwri payment method code (`cash`, `card`, `stripe`, `tap`, …) → Odoo payment journal id
 *   order_type  a fulfilment mode (`dine_in`, `takeaway`, `delivery`, …) → {tax_id?, picking_type_id?} overrides
 *
 * Anything not mapped falls back to the single store-wide fields (odoo_tax_id, the cash / bank journals, the picking
 * type). Money stays guarded where it was: OdooSync refuses an invoice whose Odoo total or tax differs from Juwri's.
 */
final class OdooMapping
{
    public const KEY = 'odoo_map';

    public const TAX_KEYS = ['store', 'untaxed'];

    public const MAX_ENTRIES = 50;

    /** @return array{tax: array<string, int>, payment: array<string, int>, order_type: array<string, array{tax_id?: int, picking_type_id?: int}>} */
    public static function read(int $storeId): array
    {
        $raw = json_decode((string) (StoreSettings::get($storeId, self::KEY) ?? ''), true);

        return self::clean(is_array($raw) ? $raw : []);
    }

    /** @param  array<string, mixed>  $map */
    public static function write(int $storeId, array $map): void
    {
        StoreSettings::set($storeId, self::KEY, (string) json_encode(self::clean($map)));
    }

    /**
     * Keep only known shapes: positive integer ids, known tax keys, slug-like method / order-type codes.
     *
     * @param  array<string, mixed>  $map
     * @return array{tax: array<string, int>, payment: array<string, int>, order_type: array<string, array{tax_id?: int, picking_type_id?: int}>}
     */
    public static function clean(array $map): array
    {
        $id = function ($v): ?int {
            if (is_int($v)) {
                return $v > 0 ? $v : null;
            }

            return is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/', $v) === 1 ? (int) $v : null;
        };
        $code = fn ($k): bool => is_string($k) && preg_match('/^[a-z0-9_]{1,40}$/', $k) === 1;
        $out = ['tax' => [], 'payment' => [], 'order_type' => []];
        foreach ((array) ($map['tax'] ?? []) as $k => $v) {
            if (in_array($k, self::TAX_KEYS, true) && ($v = $id($v)) !== null) {
                $out['tax'][$k] = $v;
            }
        }
        foreach (array_slice((array) ($map['payment'] ?? []), 0, self::MAX_ENTRIES, true) as $k => $v) {
            if ($code($k) && ($v = $id($v)) !== null) {
                $out['payment'][(string) $k] = $v;
            }
        }
        foreach (array_slice((array) ($map['order_type'] ?? []), 0, self::MAX_ENTRIES, true) as $k => $v) {
            if (! $code($k) || ! is_array($v)) {
                continue;
            }
            $entry = array_filter(['tax_id' => $id($v['tax_id'] ?? null), 'picking_type_id' => $id($v['picking_type_id'] ?? null)], fn ($x) => $x !== null);
            if ($entry !== []) {
                $out['order_type'][(string) $k] = $entry;
            }
        }

        return $out;
    }
}
