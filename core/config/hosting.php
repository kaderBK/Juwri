<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Support\HostingProfile;

/*
| P6-123 (J40): hosting profile. `shared` = cPanel without root, `cpanel-vps` = cPanel/WHM with root,
| `docker` = the container stack (default; identical to the behaviour before profiles existed).
| The profile only supplies DEFAULTS: every explicit env key (QUEUE_CONNECTION, CACHE_DRIVER,
| SESSION_DRIVER, BROADCAST_CONNECTION, BACKUP_DUMP_ENGINE, QUEUE_HEAVY_MAX_TIME, ...) still wins.
| Check a host with `php artisan juwri:preflight --profile=<p>`.
*/
return [
    'profile' => HostingProfile::normalize(env('HOSTING_PROFILE')),
    'profile_explicit' => env('HOSTING_PROFILE') !== null, // preflight asks/detects only when unset
    'worker_mode' => env('QUEUE_WORKER_MODE', HostingProfile::default('worker_mode', env('HOSTING_PROFILE'))),
    'heavy_max_time' => (int) env('QUEUE_HEAVY_MAX_TIME', HostingProfile::default('heavy_max_time', env('HOSTING_PROFILE'))),
    'default_max_time' => (int) env('QUEUE_DEFAULT_MAX_TIME', HostingProfile::default('default_max_time', env('HOSTING_PROFILE'))), // P6-119c
    'scheduler_mode' => env('SCHEDULER_MODE', HostingProfile::default('scheduler_mode', env('HOSTING_PROFILE'))),
    'docs_url' => env('HOSTING_DOCS_URL', '/deploy/HOSTING.md'), // P6-138: base of the hosting-guide links on the admin health card
    'redis' => HostingProfile::default('redis', env('HOSTING_PROFILE')),
];
