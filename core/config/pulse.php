<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Laravel\Pulse\Http\Middleware\Authorize;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders;

/*
|--------------------------------------------------------------------------
| Laravel Pulse (P1-17, MIT) — an operations dashboard for self-hosters
|--------------------------------------------------------------------------
| OFF by default (a self-hoster turns it on with PULSE_ENABLED=true; the test suite pins it off). The dashboard is
| for superadmins only: `auth` + `admin` + the `viewPulse` gate (PanelAbilities::PULSE). On a split-origin install
| it answers on the admin host. Only the cheap recorders are on, sampled; entries hold route templates, SQL
| without bindings, exception classes, job classes, hashed/grouped cache keys and a user id — never a name, email,
| phone, request body or query-string value. Tables: pulse_* (platform tables, never exported or transferred).
*/
return [

    'domain' => env('PULSE_DOMAIN') ?: (parse_url((string) env('APP_ADMIN_URL'), PHP_URL_HOST) ?: null),

    'path' => env('PULSE_PATH', 'pulse'),

    'enabled' => env('PULSE_ENABLED', false),

    'storage' => [
        'driver' => 'database',

        'trim' => [
            'keep' => env('PULSE_STORAGE_KEEP', '7 days'),
        ],

        'database' => [
            'connection' => env('PULSE_DB_CONNECTION'),
            'chunk' => 1000,
        ],
    ],

    // The ingest buffers in memory and writes after the response (the default `storage` driver); `redis` is for
    // installs that already run Redis. Never the request path.
    'ingest' => [
        'driver' => env('PULSE_INGEST_DRIVER', 'storage'),

        'buffer' => env('PULSE_INGEST_BUFFER', 5_000),

        'trim' => [
            'lottery' => [1, 1_000],
            'keep' => env('PULSE_INGEST_KEEP', '7 days'),
        ],

        'redis' => [
            'connection' => env('PULSE_REDIS_CONNECTION'),
            'chunk' => 1000,
        ],
    ],

    'cache' => env('PULSE_CACHE_DRIVER'),

    'middleware' => [
        'web',
        'auth',
        'admin',
        Authorize::class,
    ],

    'recorders' => [
        Recorders\CacheInteractions::class => [
            'enabled' => env('PULSE_CACHE_INTERACTIONS_ENABLED', true),
            'sample_rate' => env('PULSE_CACHE_INTERACTIONS_SAMPLE_RATE', 0.1),
            'ignore' => [
                ...Pulse::defaultVendorCacheKeys(),
            ],
            // Keys can carry an email, phone or token (rate limiters, per-customer caches): keep only the prefix.
            'groups' => [
                '/^job-exceptions:.*/' => 'job-exceptions:*',
                '/^([^:|]+)[:|].*$/s' => '\1:*',
            ],
        ],

        Recorders\Exceptions::class => [
            'enabled' => env('PULSE_EXCEPTIONS_ENABLED', true),
            'sample_rate' => env('PULSE_EXCEPTIONS_SAMPLE_RATE', 1),
            'location' => env('PULSE_EXCEPTIONS_LOCATION', true),
            'ignore' => [],
        ],

        Recorders\Queues::class => [
            'enabled' => env('PULSE_QUEUES_ENABLED', true),
            'sample_rate' => env('PULSE_QUEUES_SAMPLE_RATE', 0.25),
            'ignore' => [],
        ],

        Recorders\SlowQueries::class => [
            'enabled' => env('PULSE_SLOW_QUERIES_ENABLED', true),
            'sample_rate' => env('PULSE_SLOW_QUERIES_SAMPLE_RATE', 1),
            'threshold' => env('PULSE_SLOW_QUERIES_THRESHOLD', 1000),
            'location' => env('PULSE_SLOW_QUERIES_LOCATION', false),
            'max_query_length' => env('PULSE_SLOW_QUERIES_MAX_QUERY_LENGTH', 2000),
            'ignore' => [
                '/(["`])pulse_[\w]+?\1/',
            ],
        ],

        Recorders\SlowRequests::class => [
            'enabled' => env('PULSE_SLOW_REQUESTS_ENABLED', true),
            'sample_rate' => env('PULSE_SLOW_REQUESTS_SAMPLE_RATE', 1),
            'threshold' => env('PULSE_SLOW_REQUESTS_THRESHOLD', 1000),
            'ignore' => [
                '#^/'.env('PULSE_PATH', 'pulse').'$#',
            ],
        ],
    ],
];
