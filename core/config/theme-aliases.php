<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| Customizer v2 — legacy key → canonical key map
|--------------------------------------------------------------------------
|
| Before v2 the same concept was reachable through several ids: a store could
| hide its splash with `toggles.show_splash`, with the `hero` section toggle,
| or (differently) with `splash.enabled`, and the splash headline lived under
| `texts.splash_title` in ten themes but `splash.title` in the shared partial.
| v2 keeps ONE canonical id per concept; everything below resolves to it.
|
| Resolution is applied in two places:
|   - read  — theme_setting() maps an alias to its canonical id, so themes and
|             exports that still use the old id keep working for one release;
|   - write — ThemeCustomizerService folds aliases into the canonical id before
|             persisting, so nothing new is ever stored under an alias.
|
| `*` entries apply to every template; a template key adds/overrides entries
| for that template only. Section toggles are namespaced `sections.<id>` — the
| per-theme block is where a section that was only ever a splash in disguise
| gets folded into `splash.enabled`.
|
| Removal candidate: the whole map can be dropped one release after the v2
| dedup migration has run everywhere (see rev/customizer/GAPS.md WI-C0-2).
*/

$splashSection = ['sections.hero' => 'splash.enabled'];

return [

    '*' => [
        'toggles.show_splash' => 'splash.enabled',
        'toggles.show_search' => 'search.enabled',
        'texts.splash_title'  => 'splash.title',
        'texts.splash_sub'    => 'splash.subtitle',
        'texts.splash_tag'    => 'splash.subtitle',
    ],

    // Themes whose `hero` section IS the intro splash (label said so) — the
    // toggle is the same switch as splash.enabled, so it folds in. aurora's
    // `hero` is a real content hero and deliberately stays its own section.
    'crave'  => $splashSection,
    'crumb'  => $splashSection,
    'dough'  => ['sections.hero' => 'splash.enabled'],
    'ember'  => $splashSection,
    'glow'   => $splashSection,
    'lime'   => $splashSection,
    'matcha' => $splashSection,
    'plate'  => $splashSection,
    'scoop'  => $splashSection,
    'soiree' => $splashSection,
    'feast'  => $splashSection,
    'velvet' => $splashSection,
    'vivid'  => $splashSection,
    'picnic' => ['sections.onboarding' => 'splash.enabled'],
];
