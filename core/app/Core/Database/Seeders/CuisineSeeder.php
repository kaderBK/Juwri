<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Database\Seeders;

use App\Models\Cuisine;
use Illuminate\Database\Seeder;

/**
 * DIRECTORY P0-5: the canonical cuisine taxonomy.
 *
 * Names are stored in English. They are NOT rendered through bare ___() keys:
 * ___() slugs its argument, so ___('Healthy') resolves the existing platform
 * key `healthy`, which in Arabic already means "جيّد" (good) — the cuisine
 * would render as the wrong word. `pizza`, `desserts`, `bakery`, `breakfast`,
 * `vegetarian` and `vegan` collide the same way.
 *
 * T1-1 owns cuisine i18n and must use NAMESPACED keys (`cuisine_<slug>`), not
 * the bare name, and expose the names in the console so an operator can
 * correct them. Until then the English name renders in every locale, which is
 * the platform's normal untranslated-string behaviour.
 *
 * The list is deliberately MENA-weighted, because that is where the platform's
 * stores actually are: shawarma, mandi, koshari and manakish matter more here
 * than the long tail of European regional cuisines. T1-1's backfill maps store
 * titles onto these slugs, so renaming a slug later orphans its pivot rows —
 * add new rows instead.
 *
 * Idempotent: keyed upsert on slug. Safe to re-run.
 * Manual: php artisan db:seed --class=CuisineSeeder
 */
class CuisineSeeder extends Seeder
{
    /** slug => [English name, position]. Position groups related cuisines. */
    private const CUISINES = [
        // Regional staples
        'shawarma' => ['Shawarma', 10],
        'grills' => ['Grills', 20],
        'mandi' => ['Mandi & Kabsa', 30],
        'levantine' => ['Levantine', 40],
        'lebanese' => ['Lebanese', 50],
        'syrian' => ['Syrian', 60],
        'egyptian' => ['Egyptian', 70],
        'koshari' => ['Koshari', 80],
        'yemeni' => ['Yemeni', 90],
        'saudi' => ['Saudi', 100],
        'turkish' => ['Turkish', 110],
        'moroccan' => ['Moroccan', 120],
        'iraqi' => ['Iraqi', 130],
        'libyan' => ['Libyan', 140],
        'manakish' => ['Manakish & Bakery', 150],
        'falafel' => ['Falafel & Hummus', 160],
        'seafood' => ['Seafood', 170],
        // Fast food
        'burgers' => ['Burgers', 200],
        'pizza' => ['Pizza', 210],
        'sandwiches' => ['Sandwiches', 220],
        'fried-chicken' => ['Fried Chicken', 230],
        'shawaya' => ['Broasted & Shawaya', 240],
        // International
        'italian' => ['Italian', 300],
        'indian' => ['Indian', 310],
        'pakistani' => ['Pakistani', 320],
        'chinese' => ['Chinese', 330],
        'japanese' => ['Japanese', 340],
        'sushi' => ['Sushi', 350],
        'korean' => ['Korean', 360],
        'thai' => ['Thai', 370],
        'filipino' => ['Filipino', 380],
        'mexican' => ['Mexican', 390],
        'american' => ['American', 400],
        // Cafes and sweets
        'coffee' => ['Coffee', 500],
        'bakery' => ['Bakery', 510],
        'desserts' => ['Desserts', 520],
        'ice-cream' => ['Ice Cream', 530],
        'juices' => ['Juices & Smoothies', 540],
        'breakfast' => ['Breakfast', 550],
        // Dietary
        'healthy' => ['Healthy', 600],
        'vegetarian' => ['Vegetarian', 610],
        'vegan' => ['Vegan', 620],
    ];

    public function run(): void
    {
        foreach (self::CUISINES as $slug => [$name, $position]) {
            Cuisine::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'position' => $position, 'active' => true]
            );
        }
    }
}
