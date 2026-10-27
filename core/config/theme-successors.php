<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| P3-12 — old theme slug -> Community kit theme + preset (HANDOFF-TO-JUWRI.md section 3)
|--------------------------------------------------------------------------
|
| A store whose saved template is an old slug renders the kit theme the slug maps to, in the mapped preset, as soon as that
| kit theme has Blade views (Juwri\Modules\Storefront\Services\Theme\ThemeAliases::target). Until then the old theme keeps
| rendering, so a row for a theme that is not built yet is safe to list. Saved Customizer values of the old template still
| override the preset where the kit theme declares the same field (contract section 3).
|
| NOTHING is deleted (owner Q3, 2026-10-05): every old theme's views and assets stay on disk, and the D11 themes (aurora,
| velvet, ember, glow, vivid, carely, freshly, aurelia) and the display modes (pageview, folio) are not listed here.
|
| `enabled` is the platform switch (env JUWRI_THEME_ALIASES, default OFF: owner decision 2026-10-08). phpunit.xml turns it off so the suites that
| exercise the legacy themes keep testing them; the alias tests switch it on.
*/

return [
    'enabled' => (bool) env('JUWRI_THEME_ALIASES', false), // default OFF (owner, 2026-10-08): turn on with JUWRI_THEME_ALIASES=true

    // old slug => [kit theme, preset id]
    'map' => [
        'crave' => ['table', 'app'],
        'feast' => ['table', 'delivery'],
        'plate' => ['table', 'classic'],
        'dough' => ['table', 'pizzeria'],
        'pizzaflow' => ['table', 'pizzeria'],
        'soiree' => ['table', 'lounge'],
        'minimal' => ['table', 'classic'],
        'nebula' => ['table', 'app'],
        'pulse' => ['table', 'app'],
        'pandabite' => ['table', 'delivery'],
        'picnic' => ['brew', 'matcha'],
        'lime' => ['brew', 'matcha'],
        'velora' => ['brew', 'bakery'],
        'matcha' => ['brew', 'matcha'],
        'crumb' => ['brew', 'bakery'],
        'scoop' => ['brew', 'dessert'],
        'orbit' => ['brew', 'clean'],
        'zevoa' => ['basket', 'fresh'],
        'shelf' => ['aisle', 'clean'],
        'maison' => ['aisle', 'editorial'],
        'torque' => ['aisle', 'garage'],
        'lumiere' => ['aisle', 'beauty'],
        'bookla' => ['book', 'calm'],
    ],
];
