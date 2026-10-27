<?php

use App\Support\HostingProfile;

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', HostingProfile::default('queue_connection', env('HOSTING_PROFILE'))), // P6-123: profile default

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'jobs',
            'queue' => 'default',
            // P6-64b: above the longest NORMAL job $timeout (120s) so a crashed worker's
            // order/webhook jobs are redelivered within ~2.5 min. Long jobs (up to 1800s)
            // run on the `heavy` queue through the `heavy` connection below (retry_after 1860).
            // P6-139: floor 150 (longest normal job $timeout 120 + 30 s drain margin) — a lower value hands a still-running
            // 120 s money job to a second worker (double submit / double sync).
            'retry_after' => max(150, (int) env('QUEUE_RETRY_AFTER', 150)),
            // P6-64: jobs dispatched inside DB::transaction wait for the commit (and are
            // dropped on rollback) — never run against uncommitted/rolled-back rows.
            'after_commit' => true,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => 'localhost',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => max(150, (int) env('QUEUE_RETRY_AFTER', 150)), // P6-64b + P6-139 floor, see database
            'block_for' => null,
            'after_commit' => true,
        ],

        // P6-64b: the long-job lane. Same backend as the default connection (same jobs table /
        // redis connection) but a retry_after above the longest job $timeout (1800s), so a
        // still-running backup/import/AI job is never handed to a second worker. Drained by
        // `queue:work heavy --queue=heavy` (docker `queue-heavy`, supervisor, Kernel fallback).
        'heavy' => env('QUEUE_CONNECTION', 'database') === 'redis' ? [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'heavy',
            'retry_after' => (int) env('QUEUE_HEAVY_RETRY_AFTER', 1860),
            'block_for' => null,
            'after_commit' => true,
        ] : [
            'driver' => 'database',
            'connection' => null,
            'table' => 'jobs',
            'queue' => 'heavy',
            'retry_after' => (int) env('QUEUE_HEAVY_RETRY_AFTER', 1860),
            'after_commit' => true,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
