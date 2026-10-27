<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| Sentry error reporting (O2)
|--------------------------------------------------------------------------
|
| DISABLED BY DEFAULT: with a blank SENTRY_LARAVEL_DSN the package never binds
| the `sentry` container service, so the exception handler is a no-op and
| behavior is identical to today. Set SENTRY_LARAVEL_DSN in prod to enable
| aggregation + release tagging. (config/appinfo.php loads before this file,
| so config('appinfo.version') is available here for the release tag.)
|
*/

return [

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // Tag every event with the app version so regressions map to a release.
    'release' => env('SENTRY_RELEASE', config('appinfo.version')),

    'environment' => env('APP_ENV', 'production'),

    // Never ship request bodies / user PII to Sentry by default.
    'send_default_pii' => false,

    // Performance tracing off by default; opt in with a small sample rate.
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

];
