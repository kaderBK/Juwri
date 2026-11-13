<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Offline;

/**
 * Maps one offline replay record (a `pos_offline_records` row) to the manager-facing exception kinds shown on the
 * "Offline exceptions" screen (OFFLINE-POS-SPEC §4 / §8). A single row may carry several kinds — an old snapshot
 * price that also oversold — and an unknown/foreign shape yields an empty list. This never throws: it only reads.
 */
final class OfflineExceptionKinds
{
    /** The record kinds, in display order. `declined_after_offline` is a card state, not a record kind. */
    public const ALL = [
        'pricing_mismatch',
        'old_snapshot_price',
        'oversell',
        'deleted_item',
        'late_tender',
        'void_conflict',
        'cap_exceeded',
    ];

    /**
     * @param  array<string, mixed>  $row  a record with at least {type, status, reason, flags}
     * @return list<string>
     */
    public static function for(array $row): array
    {
        $flags = is_array($row['flags'] ?? null) ? $row['flags'] : [];
        $has = static fn (string $key): bool => array_key_exists($key, $flags);
        $kinds = [];

        if (($row['reason'] ?? null) === 'pricing_mismatch') {
            $kinds[] = 'pricing_mismatch';
        }
        if ($has('priced_on_old_snapshot') || $has('price_not_in_snapshot')) {
            $kinds[] = 'old_snapshot_price';
        }
        if ($has('oversell') || $has('sold_out_since')) {
            $kinds[] = 'oversell';
        }
        if ($has('item_gone')) {
            $kinds[] = 'deleted_item';
        }
        if ($has('late_for_closed_shift')) {
            $kinds[] = 'late_tender';
        }
        if (($row['type'] ?? null) === 'void' && ($row['status'] ?? null) === 'conflict') {
            $kinds[] = 'void_conflict';
        }
        if ($has('cap_exceeded')) {
            $kinds[] = 'cap_exceeded';
        }

        return $kinds;
    }
}
