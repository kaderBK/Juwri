<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use Illuminate\Support\Str;

/**
 * The best-effort swatch colour for an option label.
 *
 * One rule for the whole platform: the platform's own retail storefront
 * (maison) painted its colour rail with it, and the Liquid runtime paints
 * its catalogue filter swatches with it — so a colour NAME looks the same
 * wherever a customer meets it, whether they meet it in a product sheet or
 * in a filter panel.
 *
 * A hex code in the label wins ("#2563eb"). Then a known colour NAME inside
 * the label ("Navy Blue" → navy, "Off White" → white). Then a
 * deterministic pick from the editorial earth palette, keyed off the label's
 * own text — so the same colour name always renders the same swatch instead
 * of a flat neutral dot that reads as "no data".
 */
final class SwatchColor
{
    /** @var array<string,string> colour name → hex */
    private const NAMED = [
        'black' => '#111111', 'white' => '#ffffff', 'red' => '#d33333', 'blue' => '#2563eb',
        'green' => '#16a34a', 'yellow' => '#eab308', 'orange' => '#f97316', 'purple' => '#9333ea',
        'pink' => '#ec4899', 'brown' => '#8b5e3c', 'grey' => '#9ca3af', 'gray' => '#9ca3af',
        'navy' => '#1e3a8a', 'beige' => '#e8dcc8', 'gold' => '#d4af37', 'silver' => '#c0c0c0',
        'maroon' => '#7f1d1d', 'teal' => '#0d9488', 'cyan' => '#06b6d4', 'olive' => '#6b7a1a',
        'khaki' => '#c3b091', 'cream' => '#f5f0dc', 'camel' => '#a9784e', 'terracotta' => '#a9503b',
        'blush' => '#d9afae', 'charcoal' => '#3c3a38',
    ];

    /** @var list<string> the editorial earth palette — the fallback pick */
    private const EARTH = ['#A9784E', '#A9503B', '#6B7A4A', '#7C93A6', '#D9AFAE', '#3C3A38'];

    /**
     * The swatch colour for a label, or null when there is no label at all.
     *
     * A hex code in the label wins. Then a known colour NAME inside the
     * label — and when a label holds TWO names, the FIRST WORD wins: "Navy
     * Blue" is navy, not the blue it also contains. Without that rule a shop
     * with "Navy Blue" and "Royal Blue" paints two identical swatches, and a
     * filter whose swatches cannot be told apart is worse than no filter.
     * Then a deterministic pick from the editorial earth palette, keyed off
     * the label's own text — so the same colour name always renders the same
     * swatch instead of a flat neutral dot that reads as "no data".
     */
    public static function for(?string $label): ?string
    {
        if ($label === null || trim($label) === '') {
            return null;
        }

        $l = Str::lower($label);
        if (preg_match('/#(?:[0-9a-f]{3}|[0-9a-f]{6})\b/i', $l, $m)) {
            return $m[0];
        }

        $best = null;
        $bestStarts = false;
        foreach (self::NAMED as $name => $hex) {
            if (! str_contains($l, $name)) {
                continue;
            }
            $starts = str_starts_with($l, $name);
            if ($best === null || ($starts && ! $bestStarts)) {
                $best = $hex;
                $bestStarts = $starts;
            }
        }
        if ($best !== null) {
            return $best;
        }

        return self::EARTH[crc32($l) % count(self::EARTH)];
    }
}
