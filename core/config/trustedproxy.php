<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

$configured = trim((string) env('TRUSTED_PROXIES', ''));

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated IP addresses or CIDR ranges. Use "*" only when the
    | application is reachable exclusively through a trusted load balancer.
    |
    */
    'proxies' => $configured === '*'
        ? '*'
        : array_values(array_filter(array_map('trim', explode(',', $configured)))),

    // Caddy listen address (docker image). A bare ":port" means proxy mode: a TLS proxy sits in front (P6-55 preflight).
    'server_name' => env('SERVER_NAME'),
];
