<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Keeta;

/**
 * N2 — maps our free-text allergen titles to Keeta's required Saudi SFDA allergen
 * enums (per-SKU). Keeta rejects/flags SKUs whose allergens are not SFDA codes in the
 * Saudi market, so the pusher NEVER silently drops an allergen: anything not mapped
 * here is surfaced in an "unmapped allergens" report for the owner to reconcile.
 *
 * Codes follow the SFDA major-allergen list (the 2018 Saudi labelling requirement).
 */
class KeetaAllergenMap
{
    /** normalized title => SFDA enum code. */
    private const MAP = [
        'gluten'      => 'GLUTEN',
        'wheat'       => 'GLUTEN',
        'cereals'     => 'GLUTEN',
        'crustaceans' => 'CRUSTACEANS',
        'shellfish'   => 'CRUSTACEANS',
        'shrimp'      => 'CRUSTACEANS',
        'egg'         => 'EGGS',
        'eggs'        => 'EGGS',
        'fish'        => 'FISH',
        'peanut'      => 'PEANUTS',
        'peanuts'     => 'PEANUTS',
        'soy'         => 'SOYBEANS',
        'soya'        => 'SOYBEANS',
        'soybeans'    => 'SOYBEANS',
        'milk'        => 'MILK',
        'dairy'       => 'MILK',
        'lactose'     => 'MILK',
        'nuts'        => 'TREE_NUTS',
        'tree nuts'   => 'TREE_NUTS',
        'almond'      => 'TREE_NUTS',
        'almonds'     => 'TREE_NUTS',
        'cashew'      => 'TREE_NUTS',
        'walnut'      => 'TREE_NUTS',
        'celery'      => 'CELERY',
        'mustard'     => 'MUSTARD',
        'sesame'      => 'SESAME',
        'sesame seeds'=> 'SESAME',
        'sulphites'   => 'SULPHITES',
        'sulfites'    => 'SULPHITES',
        'lupin'       => 'LUPIN',
        'molluscs'    => 'MOLLUSCS',
        'mollusks'    => 'MOLLUSCS',
    ];

    /** SFDA code for a title, or null when unmapped. */
    public static function code(string $title): ?string
    {
        $key = strtolower(trim($title));
        return self::MAP[$key] ?? null;
    }

    /**
     * Map a list of allergen titles → [codes, unmapped].
     *
     * @return array{0:array<int,string>,1:array<int,string>}  [sfdaCodes, unmappedTitles]
     */
    public static function map(array $titles): array
    {
        $codes = [];
        $unmapped = [];
        foreach ($titles as $t) {
            $code = self::code((string) $t);
            if ($code !== null) {
                $codes[$code] = true;
            } else {
                $unmapped[] = (string) $t;
            }
        }
        return [array_keys($codes), array_values(array_unique($unmapped))];
    }
}
