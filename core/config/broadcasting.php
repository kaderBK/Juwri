<?php

use App\Support\HostingProfile;

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
| Broadcasting overrides. Anything not set here comes from the framework's
| own defaults (reverb, pusher, ably, log and null connections), which
| Laravel merges in when the configuration loads.
*/

return [

    // OFF by default: "null" (or "log") means no event ever leaves the app and the screens keep polling.
    // Live updates (Reverb) switch on with BROADCAST_CONNECTION=reverb. The deployed .env files may still use the
    // pre-Laravel-11 name BROADCAST_DRIVER, so it stays as the fallback.
    'default' => env('BROADCAST_CONNECTION', env('BROADCAST_DRIVER', HostingProfile::default('broadcast_connection', env('HOSTING_PROFILE')))),

    'connections' => [

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],

    ],

];
