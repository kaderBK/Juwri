<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    /*
    |--------------------------------------------------------------------------
    | LAN print bridge (rev/parity/contracts/print-bridge.md)
    |--------------------------------------------------------------------------
    |
    | The bridge listens on the TILL's loopback interface, so the party that can
    | reach it is normally the BROWSER, not this server. `server_bridge` is the
    | single-host exception: when the app itself runs on the POS machine, the
    | dispatcher may deliver bridge jobs server-side instead of parking them for
    | the page to collect. Off by default — on a hosted install 127.0.0.1 is the
    | web server, not the cashier's PC.
    |
    */

    'server_bridge' => env('PRINT_SERVER_BRIDGE', false),

    'bridge_url' => env('PRINT_BRIDGE_URL', 'http://127.0.0.1:8543'),

    // Web → bridge fetch timeout, then two retries with the SAME jobId.
    'bridge_timeout' => 15,
    'bridge_retries' => 2,

    // /health is cached for at most 30s and re-probed after any failed job.
    'health_ttl' => 30,

    /*
    |--------------------------------------------------------------------------
    | Queue hygiene
    |--------------------------------------------------------------------------
    |
    | How long a rendered job stays collectable. A ticket nobody claimed within
    | this window is stale: printing lunch orders at dinner is worse than not
    | printing them.
    |
    */

    'job_ttl_minutes' => env('PRINT_JOB_TTL_MINUTES', 30),

    // Bridge contract: decoded payload must be ≤ 512 KB.
    'max_payload_bytes' => 512 * 1024,

];
