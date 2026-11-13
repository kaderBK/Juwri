<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Skin;

use Juwri\Modules\Menu\Models\Menu;

/**
 * Deterministic mock skin analysis. Metrics are derived from a hash of the image
 * payload (so the same capture yields the same result and the UI is stable), and
 * product picks are REAL items from the store's catalog so "AI Recommendation"
 * always links to buyable products. Swap for a real CV/vision provider later.
 */
class MockSkinScanProvider implements SkinScanProvider
{
    public function analyze(?string $imageData, ?int $storeId = null): array
    {
        $seed = crc32((string) ($imageData ?: 'demo').'|'.(string) $storeId);
        $pick = fn (int $lo, int $hi, int $salt) => $lo + (($seed >> $salt) % max(1, $hi - $lo + 1));

        $metrics = [
            'acne' => $pick(8, 42, 0),
            'oily' => $pick(20, 70, 3),
            'wrinkles' => $pick(5, 35, 6),
            'dryness' => $pick(15, 55, 9),
        ];
        $skinAge = $pick(22, 41, 12);

        // Real product picks from the store (active only). Only the raw ids are
        // needed here (cast to int below), so a query-level pluck is correct —
        // no accessor runs, nothing is bypassed [query-pluck-bypasses-accessors].
        $ids = [];
        if ($storeId !== null) {
            $ids = Menu::where('store_id', $storeId)
                ->where('active', '1') // enum('0','1') — string, never int 1 [mysql-enum-active-index-trap]
                ->inRandomOrder() // portable: RAND() on MySQL, RANDOM() on sqlite [sqlite-mysql-driver-gap]
                ->limit(4)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        arsort($metrics);
        $top = array_key_first($metrics);
        $recommendation = match ($top) {
            'oily' => 'Your skin reads oily in the T-zone — a lightweight gel cleanser and an oil-free moisturizer will balance it.',
            'dryness' => 'Dryness is your main concern — layer a hydrating serum under a richer moisturizer, morning and night.',
            'wrinkles' => 'Early fine lines detected — add a retinol at night and a daily SPF to slow their progress.',
            default => 'Congestion and breakouts are your focus — a salicylic-acid cleanser plus a non-comedogenic moisturizer will help.',
        };
        // restore concern order for display (acne, oily, wrinkles, dryness)
        $metrics = [
            'acne' => $metrics['acne'],
            'oily' => $metrics['oily'],
            'wrinkles' => $metrics['wrinkles'],
            'dryness' => $metrics['dryness'],
        ];

        return [
            'skin_age' => $skinAge,
            'metrics' => $metrics,
            'recommendation' => $recommendation,
            'recommended_menu_ids' => $ids,
        ];
    }
}
