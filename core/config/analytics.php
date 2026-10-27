<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [
    /*
    |--------------------------------------------------------------------------
    | K9: AI analytics copilot (dark)
    |--------------------------------------------------------------------------
    | The grounded sales copilot + peer-benchmark Insights page are OFF by
    | default. Set AI_COPILOT_ENABLED=true to expose the Insights page. The
    | benchmark card only renders for cohorts above the k-anonymity floor
    | (benchmarks:compute publishes only cohorts with >= MIN_COHORT stores).
    */
    'copilot_enabled' => env('AI_COPILOT_ENABLED', false),
];
