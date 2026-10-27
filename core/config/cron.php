<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    /*
    |--------------------------------------------------------------------------
    | Cron trigger token
    |--------------------------------------------------------------------------
    |
    | Shared secret for the public /cronjob endpoint, which runs `schedule:run`.
    | Send it in the `X-Juwri-Cron-Token` header (or a POST body field `token`),
    | never in the URL. When empty (unset), the endpoint returns 404 so an
    | unconfigured box cannot be pinged. The CLI cron (`php artisan
    | schedule:run` every minute) is preferred; this is a last resort.
    | See security item S2 and P6-119f.
    |
    */

    'token' => env('CRON_TOKEN', ''),

    /*
    | DEPRECATED: accept `?token=` in the query string (old setups only). The
    | secret then ends up in access logs. Off by default; each use logs a warning.
    */
    'allow_query_token' => (bool) env('CRON_ALLOW_QUERY_TOKEN', false),

    /*
    | Run-time cap (seconds) for one HTTP-triggered run; keep it under the
    | host's PHP / proxy timeout. A lock stops overlapping runs.
    */
    'max_seconds' => (int) env('CRON_MAX_SECONDS', 50),

];
