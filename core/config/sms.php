<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| Platform SMS fallback credentials
|--------------------------------------------------------------------------
|
| P6 (config:cache safety): SmsService read these straight from env() on the
| hot send path, so after `config:cache` — which stops parsing .env — every
| one returned null and the platform SMS fallback silently went dark. The
| per-tenant BYOK credentials (the Integrations vault) are unaffected; this
| block is only the platform-level fallback the service uses when a store has
| no credential of its own. Values are byte-identical when uncached.
|
*/

return [
    'provider' => env('SMS_PROVIDER', 'log'),
    // Local fixture escape hatch only. Production inbound callbacks must prove
    // provider origin before STOP/START or agent-ordering side effects.
    'inbound_allow_unsigned' => env('SMS_INBOUND_ALLOW_UNSIGNED', false),

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],

    'nexmo' => [
        'key' => env('NEXMO_KEY') ?: env('VONAGE_KEY'),
        'secret' => env('NEXMO_SECRET') ?: env('VONAGE_SECRET'),
        'from' => env('NEXMO_FROM') ?: env('VONAGE_FROM'),
    ],

    // Msegat (KSA) — plain HTTPS, per-request userName + apiKey + sender.
    'msegat' => [
        'username' => env('MSEGAT_USERNAME'),
        'api_key' => env('MSEGAT_API_KEY'),
        'sender' => env('MSEGAT_SENDER'),
    ],
];
