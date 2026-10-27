<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    /*
    |--------------------------------------------------------------------------
    | Blog Autopilot — structural defaults
    |--------------------------------------------------------------------------
    | Runtime toggles live in the Option table (settings):
    |   blog_autopilot_enabled   '0'|'1'  master switch (default off)
    |   blog_autopilot_auto      '0'|'1'  full-auto publish mode (default off —
    |                                     draft mode: posts land as 'pending')
    |   blog_autopilot_images    '0'|'1'  AI hero image per post (default on)
    |   blog_autopilot_weekly_cap  int    overrides weekly_publish_cap below
    |   blog_autopilot_locales   csv      target locales auto-translated on publish
    |   blog_autopilot_author    int      admin user id used as post author
    | This file holds only the non-UI knobs.
    */

    // Hard ceiling on auto-published posts per rolling 7 days (full-auto mode).
    'weekly_publish_cap' => (int) env('BLOG_AUTOPILOT_WEEKLY_CAP', 3),

    // Topics drafted per scheduler tick.
    'draft_batch' => (int) env('BLOG_AUTOPILOT_DRAFT_BATCH', 2),

    // Cost guardrail: stop generating once this many platform posts are drafted
    // but not yet published/rejected. In full-auto the publish cap (3/week) is
    // far slower than the draft rate, so without this ceiling drafts pile up and
    // AI spend runs away. Applies to draft AND full-auto mode.
    'max_drafted_backlog' => (int) env('BLOG_AUTOPILOT_MAX_BACKLOG', 10),

    // A generation job that flips a topic to 'drafting' and then dies (SIGKILL /
    // OOM / hard timeout) never runs its catch, so the topic would stick forever.
    // The scheduler reaps 'drafting' topics older than this back to 'failed'.
    // Must exceed the job timeout (600s) plus a safety margin.
    'stuck_after_minutes' => (int) env('BLOG_AUTOPILOT_STUCK_MINUTES', 20),

    // Article length window (words) fed to the outline/section prompts.
    'min_words' => 700,
    'max_words' => 1500,

    // Outline size window (number of H2 sections).
    'sections' => [4, 7],

    // Queue lane for generation jobs (the scheduler already drains 'heavy').
    'queue' => env('BLOG_AUTOPILOT_QUEUE', 'heavy'),

    // Plan-settings key for the per-reseller monthly AI-post cap.
    // 0 = feature off for the plan; 999 = unlimited (platform sentinel).
    'reseller_cap_key' => 'ai_blog_posts_limit',

    // Optional: your blended AI text price per 1,000 tokens (USD), used ONLY to
    // show an ESTIMATED spend next to the real token counts in the admin usage
    // card. Leave null to show tokens/calls without a dollar figure — ai_usage
    // stores tokens, not billed cost, so any dollar value is an estimate.
    'cost_per_1k_tokens' => env('BLOG_AUTOPILOT_COST_PER_1K') !== null
        ? (float) env('BLOG_AUTOPILOT_COST_PER_1K')
        : null,
];
