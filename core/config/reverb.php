<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
| Laravel Reverb (the WebSocket server for live updates: orders list, KDS, POS). OFF unless BROADCAST_CONNECTION=reverb.
| Single-process setup: no Redis scaling, no database. Run it with `php artisan reverb:start` (docker/compose has a
| `reverb` service). The app publishes to it over HTTP (config/broadcasting.php, connection "reverb"); browsers connect
| to the "public" endpoint below and authorize each private channel through POST /broadcasting/auth.
*/

return [

    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => false,
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                // comma list of origin hosts; default = the app's own host (private channels are signed, this is defence in
                // depth). P5-19 G3: no REVERB_ALLOWED_ORIGINS and no host in APP_URL = an empty list, so every browser
                // origin is refused (never '*'); set REVERB_ALLOWED_ORIGINS explicitly.
                'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('REVERB_ALLOWED_ORIGINS', (string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST)))))),
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
            ],
        ],

    ],

    /*
    | What the BROWSER connects to (empty = the publish host/port/scheme above). Behind a TLS proxy this is the public
    | wss host, e.g. REVERB_PUBLIC_HOST=ws.example.com, REVERB_PUBLIC_PORT=443, REVERB_PUBLIC_SCHEME=https.
    */
    'public' => [
        'host' => env('REVERB_PUBLIC_HOST') ?: env('REVERB_HOST'),
        'port' => env('REVERB_PUBLIC_PORT') ?: env('REVERB_PORT', 443),
        'scheme' => env('REVERB_PUBLIC_SCHEME') ?: env('REVERB_SCHEME', 'https'),
    ],

];
