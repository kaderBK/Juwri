<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| Customizer v2 — WI-T2 · self-hosted font-pair registry
|--------------------------------------------------------------------------
|
| One entry per curated pairing. `fonts.pair` (declared in the shared `tokens`
| schema fragment) picks one; Juwri\Modules\Storefront\Services\Theme\FontPairs turns it into the
| @font-face block that store_templates/_partials/theme-fonts.blade.php emits and
| into the `--tf-heading` / `--tf-body` stacks Juwri\Modules\Storefront\Services\Theme\Tokens emits.
|
| ─── Rules this file obeys ────────────────────────────────────────────────────
|
|  1. SELF-HOSTED ONLY. Every `file` is a WOFF2 already committed to this repo
|     and served same-origin through asset(). No Google Fonts, no CDN — CSP
|     blocks third-party hosts and RESEARCH.md §6 bans them outright.
|     ThemeFontPairsTest fails if a path does not exist on disk.
|
|  2. EVERY PAIR HAS AN ARABIC FACE. The platform's default locale is `ar`; a
|     Latin-only pair would drop an Arabic storefront to a system fallback the
|     owner never chose. Each pair therefore carries at least one face with
|     `script => arabic`, and that family appears in BOTH stacks (after the
|     Latin family, so Latin text still gets the Latin design and Arabic text
|     falls through to the Arabic face — the Latin faces have zero Arabic
|     coverage, which is exactly what makes the fall-through deterministic).
|
|     The only Arabic family committed to this repo today is IBM Plex Sans
|     Arabic, so every pair shares it. Pairs differ in their LATIN faces. When a
|     second Arabic family (Cairo, Tajawal, Amiri — all OFL, all named in
|     RESEARCH.md §6) is subsetted and committed, add pairs that use it here;
|     nothing else has to change.
|
|  3. NO INVENTED ENTRIES. Only files that exist are referenced. The candidate
|     pairs RESEARCH.md §6 lists which have NO committed woff2 (Rubik, Cairo,
|     Tajawal, Playfair Display + Amiri, Space Grotesk) are deliberately absent
|     rather than declared and broken.
|
| ─── Face shape ───────────────────────────────────────────────────────────────
|   family        CSS font-family name the @font-face declares
|   script        'latin' | 'arabic' — what the face covers (used by the test
|                 that enforces rule 2, and by nothing at runtime)
|   file          repo-relative asset path, passed to asset()
|   weight        `400` or a variable range `200 800`
|   style         normal | italic
|   unicode_range optional; REQUIRED when two files share one family (the
|                 latin / latin-ext splits), otherwise the later declaration
|                 would win for every codepoint
|
| ─── A note on the source directories ─────────────────────────────────────────
| `assets/fonts/` is the neutral home and is preferred. Jakarta/Fraunces live
| under `assets/marketplace-themes/crave/1/fonts/` because those are the only
| committed copies (Syne/Instrument/Geist Mono moved to `assets/fonts/` in
| WI-16h). Both are version-scoped directories: if either theme is ever
| re-versioned, copy the woff2 into `assets/fonts/` and repoint the paths here —
| ThemeFontPairsTest will fail loudly the moment a file moves.
*/

$arabicRange = 'U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0898-08E1, U+08E3-08FF, '
    .'U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC';

$latinRange = 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, '
    .'U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD';

$latinExtRange = 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, '
    .'U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, '
    .'U+2113, U+2C60-2C7F, U+A720-A7FF';

$arabicFamily = 'IBM Plex Sans Arabic';

/** The Arabic half of every pair: IBM Plex Sans Arabic, regular + bold. */
$arabicFaces = [
    [
        'family' => $arabicFamily, 'script' => 'arabic',
        'file' => 'assets/fonts/plex-arabic-400.woff2',
        'weight' => '400', 'style' => 'normal', 'unicode_range' => $arabicRange,
    ],
    [
        'family' => $arabicFamily, 'script' => 'arabic',
        'file' => 'assets/fonts/plex-arabic-700.woff2',
        'weight' => '700', 'style' => 'normal', 'unicode_range' => $arabicRange,
    ],
];

/** Inter, static Latin subsets (400/600/800) — the neutral workhorse. */
$interFaces = [
    ['family' => 'Inter', 'script' => 'latin', 'file' => 'assets/fonts/inter-latin-400.woff2', 'weight' => '400', 'style' => 'normal'],
    ['family' => 'Inter', 'script' => 'latin', 'file' => 'assets/fonts/inter-latin-600.woff2', 'weight' => '600', 'style' => 'normal'],
    ['family' => 'Inter', 'script' => 'latin', 'file' => 'assets/fonts/inter-latin-800.woff2', 'weight' => '800', 'style' => 'normal'],
];

/** Plus Jakarta Sans, variable 200–800, Latin subset. */
$jakartaFaces = [[
    'family' => 'Plus Jakarta Sans', 'script' => 'latin',
    'file' => 'assets/marketplace-themes/crave/1/fonts/jakarta-latin.woff2',
    'weight' => '200 800', 'style' => 'normal', 'unicode_range' => $latinRange,
]];

/** Fraunces, variable 100–900 (opsz axis left at its default), Latin subset. */
$frauncesFaces = [[
    'family' => 'Fraunces', 'script' => 'latin',
    'file' => 'assets/marketplace-themes/crave/1/fonts/fraunces-latin.woff2',
    'weight' => '100 900', 'style' => 'normal', 'unicode_range' => $latinRange,
]];

/** Syne, variable 400–800, split latin / latin-ext (unicode_range mandatory). */
$syneFaces = [
    [
        'family' => 'Syne', 'script' => 'latin',
        'file' => 'assets/fonts/syne-latin.woff2',
        'weight' => '400 800', 'style' => 'normal', 'unicode_range' => $latinRange,
    ],
    [
        'family' => 'Syne', 'script' => 'latin',
        'file' => 'assets/fonts/syne-latin-ext.woff2',
        'weight' => '400 800', 'style' => 'normal', 'unicode_range' => $latinExtRange,
    ],
];

/** Instrument Sans, variable 400–700, split latin / latin-ext + italics. */
$instrumentFaces = [
    [
        'family' => 'Instrument Sans', 'script' => 'latin',
        'file' => 'assets/fonts/instrument-sans-latin.woff2',
        'weight' => '400 700', 'style' => 'normal', 'unicode_range' => $latinRange,
    ],
    [
        'family' => 'Instrument Sans', 'script' => 'latin',
        'file' => 'assets/fonts/instrument-sans-latin-ext.woff2',
        'weight' => '400 700', 'style' => 'normal', 'unicode_range' => $latinExtRange,
    ],
    [
        'family' => 'Instrument Sans', 'script' => 'latin',
        'file' => 'assets/fonts/instrument-sans-italic-latin.woff2',
        'weight' => '400 700', 'style' => 'italic', 'unicode_range' => $latinRange,
    ],
    [
        'family' => 'Instrument Sans', 'script' => 'latin',
        'file' => 'assets/fonts/instrument-sans-italic-latin-ext.woff2',
        'weight' => '400 700', 'style' => 'italic', 'unicode_range' => $latinExtRange,
    ],
];

/** Geist Mono, variable 100–900, Latin subset. */
$geistFaces = [[
    'family' => 'Geist Mono', 'script' => 'latin',
    'file' => 'assets/fonts/geist-mono-var.woff2',
    'weight' => '100 900', 'style' => 'normal', 'unicode_range' => $latinRange,
]];

$sans = '-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif';
$serif = 'Georgia, Times New Roman, serif';
$mono = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';

return [

    /*
     | The value `fonts.pair` carries when the owner has NOT chosen a pairing —
     | the schema default, and the option labelled "Theme default".
     |
     | It is a SENTINEL, not a pair: nothing in `pairs` may use this id, so
     | FontPairs yields no faces and no stacks for it and Tokens emits no
     | --tf-* var. That inertness is the WI's parity contract and is pinned by
     | ThemeFontPairsTest.
     |
     | Why not the empty string (the obvious choice)? The editor renders a
     | `select` with Alpine's `<option :value="opt">`, and Alpine REMOVES an
     | attribute it is asked to bind to ''. An `<option>` with no `value`
     | attribute reports its TEXT as its value, so choosing "Theme default"
     | submitted the literal string "Theme default" — which the schema validator
     | then rejected as an invalid option, leaving an owner who had picked a pair
     | with no way back to their theme's own typography. Verified in Chrome on
     | /theme-customizer. A non-empty sentinel sidesteps it without touching the
     | editor blade (owned by another lane); FontPairs still treats '' as "no
     | pair" too, so any row already carrying '' behaves identically.
     */
    'none' => 'none',

    'arabic_family' => $arabicFamily,

    'pairs' => [

        'inter' => [
            'label' => 'Inter',
            'note' => 'Neutral UI sans, one family throughout.',
            'faces' => array_merge($interFaces, $arabicFaces),
            'heading' => "Inter, '{$arabicFamily}', {$sans}",
            'body' => "Inter, '{$arabicFamily}', {$sans}",
        ],

        'jakarta' => [
            'label' => 'Plus Jakarta Sans',
            'note' => 'Warm geometric sans, one family throughout.',
            'faces' => array_merge($jakartaFaces, $arabicFaces),
            'heading' => "'Plus Jakarta Sans', '{$arabicFamily}', {$sans}",
            'body' => "'Plus Jakarta Sans', '{$arabicFamily}', {$sans}",
        ],

        'fraunces-jakarta' => [
            'label' => 'Fraunces + Plus Jakarta Sans',
            'note' => 'Editorial serif headings over a geometric sans body.',
            'faces' => array_merge($frauncesFaces, $jakartaFaces, $arabicFaces),
            'heading' => "Fraunces, '{$arabicFamily}', {$serif}",
            'body' => "'Plus Jakarta Sans', '{$arabicFamily}', {$sans}",
        ],

        'syne-instrument' => [
            'label' => 'Syne + Instrument Sans',
            'note' => 'Display headings with a quiet grotesque body.',
            'faces' => array_merge($syneFaces, $instrumentFaces, $arabicFaces),
            'heading' => "Syne, '{$arabicFamily}', {$sans}",
            'body' => "'Instrument Sans', '{$arabicFamily}', {$sans}",
        ],

        'instrument' => [
            'label' => 'Instrument Sans',
            'note' => 'Modern grotesque, one family throughout.',
            'faces' => array_merge($instrumentFaces, $arabicFaces),
            'heading' => "'Instrument Sans', '{$arabicFamily}', {$sans}",
            'body' => "'Instrument Sans', '{$arabicFamily}', {$sans}",
        ],

        'geist-inter' => [
            'label' => 'Geist Mono + Inter',
            'note' => 'Monospaced headings over Inter — technical, speciality.',
            'faces' => array_merge($geistFaces, $interFaces, $arabicFaces),
            'heading' => "'Geist Mono', '{$arabicFamily}', {$mono}",
            'body' => "Inter, '{$arabicFamily}', {$sans}",
        ],
    ],
];
