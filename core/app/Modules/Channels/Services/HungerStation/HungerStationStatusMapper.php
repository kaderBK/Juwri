<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
namespace Juwri\Modules\Channels\Services\HungerStation;
/**
 * P3 — map a local order/KDS status to a HungerStation status, per picking mode.
 * Partner picking: our KDS drives pick/pack. Pelican: HS app picks (we mostly emit
 * ready/cancel). Returns null when a local status has no HS counterpart (no push).
 */
class HungerStationStatusMapper {
    public static function toHs(string $localStatus, string $pickingMode = 'partner'): ?string {
        $common = [
            'ready'     => 'READY_FOR_PICKUP',
            'cancelled' => 'CANCELLED',
            'canceled'  => 'CANCELLED',
        ];
        if ($pickingMode === 'pelican') {
            return $common[$localStatus] ?? null;
        }
        // partner picking also emits dispatch/complete
        return array_merge($common, [
            'dispatched' => 'DISPATCHED',
            'completed'  => 'DISPATCHED',
            'delivered'  => 'DISPATCHED',
        ])[$localStatus] ?? null;
    }
}
