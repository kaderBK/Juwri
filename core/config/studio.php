<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [
    // Nano Banana (Google Gemini image model). Configurable per decision D1:
    // default Nano Banana 2 Lite; swap the model id without code changes.
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('STUDIO_IMAGE_MODEL', 'gemini-3.1-flash-lite-image'),
        'base_url' => env('STUDIO_IMAGE_BASE', 'https://generativelanguage.googleapis.com/v1beta'),
        // P1-23: same flag as ai.providers.gemini.no_training (Gemini trains on free-tier data; paid tier does not).
        'no_training' => (bool) env('GEMINI_NO_TRAINING', false),
    ],

    // Credit cost per paid action. Placeholder values — the owner tunes these to
    // cover cost + margin (do not hard-code final prices).
    'credit_cost' => [
        'pdf_design' => (int) env('STUDIO_COST_PDF_DESIGN', 5),
    ],

    // #99 abuse/cost cap: max Nano Banana generations per guest session.
    'max_designs_per_session' => (int) env('STUDIO_MAX_DESIGNS', 20),

    // #61 credit bundles — one-time "Recharge" tiers (placeholder pricing/SAR; owner
    // tunes). No subscription. quick/quality/max are review copy for the modal.
    'currency' => env('STUDIO_CURRENCY', 'SAR'),
    'bundles' => [
        ['key' => 'starter', 'name' => 'Starter', 'price' => 39,  'credits' => 30,   'tagline' => 'For trying it out',      'quick' => 30,   'quality' => 2,  'max' => 1,  'recommended' => false],
        ['key' => 'creator', 'name' => 'Creator', 'price' => 81,  'credits' => 120,  'tagline' => 'For the first real menus', 'quick' => 120,  'quality' => 10, 'max' => 4,  'recommended' => true],
        ['key' => 'pro',     'name' => 'Pro',     'price' => 210, 'credits' => 420,  'tagline' => 'For regular menu work',   'quick' => 420,  'quality' => 35, 'max' => 14, 'recommended' => false],
        ['key' => 'studio',  'name' => 'Studio',  'price' => 425, 'credits' => 1000, 'tagline' => 'For many menus and teams', 'quick' => 1000, 'quality' => 83, 'max' => 33, 'recommended' => false],
    ],

    // #31 style presets that seed the Nano Banana prompt (tap instead of describing).
    'presets' => [
        'fine_dining' => 'elegant fine-dining, serif type, cream and gold, generous whitespace',
        'cafe' => 'cozy cafe, warm tones, hand-drawn accents, friendly type',
        'street_food' => 'bold street-food, high contrast, vibrant colours, chunky type',
        'minimal' => 'minimalist, black on white, clean grid, lots of space',
    ],

    // #35 supported output aspect ratios.
    'aspects' => ['a4', 'letter', 'square', 'story'],

    // Template engine (studio-templates build): package dir for HTML/CSS menu
    // templates. Null = resources/views/studio/templates. Tests point elsewhere.
    'templates_path' => env('STUDIO_TEMPLATES_PATH'),

    // P2: print export via headless Chrome (HeadlessChrome, J18). chrome_path null lets
    // puppeteer resolve its own browser; on dev boxes point it at system Chrome.
    'export' => [
        'chrome_path' => env('STUDIO_CHROME_PATH'),
        'node_path' => env('STUDIO_NODE_PATH'),
    ],

    // #62 referral bonus credits (granted to both referrer + referee).
    'referral_bonus' => (int) env('STUDIO_REFERRAL_BONUS', 5),

    // Welcome credits for a brand-new guest session (0 = off). Keep small if
    // enabled — clearing cookies mints a fresh session.
    'welcome_credits' => (int) env('STUDIO_WELCOME_CREDITS', 0),
];
