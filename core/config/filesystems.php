<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    /*
    | Where the owner's account-export archives are kept (a folder per account id below it). Default: storage/app/account-exports.
    | The tests point it at a folder of their own per test: account ids repeat in every parallel test process, and a shared
    | folder let one process delete another's archive.
    */
    'account_exports_path' => env('ACCOUNT_EXPORTS_PATH'),

    /*
    | Where the Reserve-with-Google feed artifacts are written. Default: storage/app/rwg.
    | The tests point it at a folder of their own per test, so parallel processes never delete each other's files.
    */
    'rwg_feeds_path' => env('RWG_FEEDS_PATH'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            // Private files live directly under storage/app (pre-Laravel-11
            // layout); no signed "serve" route is registered for this disk.
            'root' => storage_path('app'),
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    // The link location is the repo-root storage/ (AppServiceProvider moves the public path to base_path('../')
    // during register(), AFTER config is loaded), so it is spelled from base_path() here: public_path() at config
    // time still points at the non-existent core/public and `storage:link` died with symlink(): No such file.
    // Nothing calls storage:link at runtime (shared hosting may forbid symlink(), J37/J40); it is an install-time convenience.
    'links' => [
        base_path('../storage') => storage_path('app/public'),
    ],

];
