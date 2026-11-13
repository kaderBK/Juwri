<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| Theme Studio
|--------------------------------------------------------------------------
|
| `ai` — the AI theme builder (phase D, rev/theme-studio/AI-BUILDER-CONTRACT.md).
| The feature itself ships dark behind the `ai_theme_builder` feature key;
| these are the operating limits once it is on.
|
|   enabled      false = every generation uses the rule-based composer
|                (recipe + logo palette + default fonts + library copy)
|   daily_limit  generations per store per day (429 above it)
|   ttl_days     how long a generated proposal set can be previewed/applied
|   max_sections a proposal never carries more sections than this
|   image_cost   Studio credits one AI hero image costs (opt-in, refunded
|                when the image fails)
|   max_tokens   output budget of the compose call (3 proposals as JSON)
|   copy_limits  per setting type, the longest copy the builder keeps
*/

return [
    'ai' => [
        'enabled' => filter_var(env('THEME_STUDIO_AI_ENABLED', true), FILTER_VALIDATE_BOOL),
        'daily_limit' => (int) env('THEME_STUDIO_AI_DAILY_LIMIT', 10),
        'ttl_days' => (int) env('THEME_STUDIO_AI_TTL_DAYS', 7),
        'max_sections' => (int) env('THEME_STUDIO_AI_MAX_SECTIONS', 14),
        'image_cost' => (int) env('THEME_STUDIO_AI_IMAGE_COST', 5),
        'max_tokens' => (int) env('THEME_STUDIO_AI_MAX_TOKENS', 8000),
        'copy_limits' => [
            'text' => 80,
            'textarea' => 240,
            'richtext' => 600,
        ],
        // Render-check languages per proposal (default language first).
        'check_languages' => 3,
    ],
];
