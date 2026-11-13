<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * Pure-PHP geo math for the marketplace (P0-1). Polygon work stays in PHP —
 * never raw SQL (house rule: prefix-safe queries, bare columns only).
 */
class Geo
{
    /**
     * Ray-casting point-in-polygon. $polygon is an array of [lat, lng] pairs
     * forming a ring; the closing edge back to the first vertex is implied.
     * Points exactly on an edge count as inside.
     */
    public static function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $n = count($polygon);
        if ($n < 3) {
            return false;
        }

        $inside = false;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$latI, $lngI] = [(float) $polygon[$i][0], (float) $polygon[$i][1]];
            [$latJ, $lngJ] = [(float) $polygon[$j][0], (float) $polygon[$j][1]];

            if (self::onSegment($lat, $lng, $latI, $lngI, $latJ, $lngJ)) {
                return true;
            }

            $intersects = (($lngI > $lng) !== ($lngJ > $lng))
                && ($lat < ($latJ - $latI) * ($lng - $lngI) / ($lngJ - $lngI) + $latI);
            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Haversine distance in kilometres.
     */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    private static function onSegment(float $pLat, float $pLng, float $aLat, float $aLng, float $bLat, float $bLng): bool
    {
        $eps = 1e-9;
        $cross = ($bLat - $aLat) * ($pLng - $aLng) - ($bLng - $aLng) * ($pLat - $aLat);
        if (abs($cross) > $eps) {
            return false;
        }

        return $pLat >= min($aLat, $bLat) - $eps && $pLat <= max($aLat, $bLat) + $eps
            && $pLng >= min($aLng, $bLng) - $eps && $pLng <= max($aLng, $bLng) + $eps;
    }
}
