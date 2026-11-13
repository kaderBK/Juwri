<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Verticals;

use Juwri\Core\Models\Store;

/**
 * rev/multi-vertical F0-2 — per-vertical UI vocabulary.
 *
 * Maps a noun key ('item','catalog','add_to_cart',…) to the right word for a
 * store's group (food|goods|services), then translates it through ___() so every
 * locale works (DECISIONS A5). Restaurant/food keeps today's exact wording, so
 * legacy stores read unchanged (DECISIONS D4 — relabel, never rename).
 *
 * Usage in a blade:  {{ app(VerticalVocab::class)->for($store->store_type, 'catalog') }}
 * Never hardcode "Menu"/"Dish" in a new view — call this.
 */
class VerticalVocab
{
    public function __construct(private VerticalRegistry $registry) {}

    /** The translated noun for a store type + key. */
    public function for(?string $storeType, string $key): string
    {
        return ___($this->raw($storeType, $key));
    }

    /** The untranslated English word (useful when you must translate elsewhere). */
    public function raw(?string $storeType, string $key): string
    {
        $group = (string) $this->registry->capability($storeType, 'group', 'food');
        $vocab = (array) config('verticals.vocab', []);

        return $vocab[$group][$key]
            ?? $vocab['food'][$key]
            ?? $key;
    }

    /** Convenience for a store instance. */
    public function forStore(Store $store, string $key): string
    {
        return $this->for($store->store_type ?? null, $key);
    }

    /**
     * The full translated noun map for a store type — hand to a blade once and
     * read keys off it, instead of many calls.
     *
     * @return array<string,string>
     */
    public function map(?string $storeType): array
    {
        $group = (string) $this->registry->capability($storeType, 'group', 'food');
        $vocab = (array) config('verticals.vocab', []);
        $keys = array_keys($vocab['food'] ?? []);

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->for($storeType, $key);
        }

        return $out;
    }
}
