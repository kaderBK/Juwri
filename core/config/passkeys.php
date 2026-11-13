<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| Passkeys (P1-19, J25-13)
|--------------------------------------------------------------------------
|
| WebAuthn sign-in for `users` (owners, superadmins, reseller owners) through the first-party
| laravel/passkeys package, standalone (no Fortify). The package's own routes are switched off
| (AppServiceProvider): juwri's PasskeyController wraps its actions so a passkey sign-in runs the same
| checks as a password sign-in (blocked accounts, host split, rate limits, audit, session renewal).
|
| The relying party (RP ID + allowed origin) is NOT fixed here: Juwri\Modules\Auth\Support\PasskeyRelyingParty
| sets both per request from the platform host that serves the page (APP_URL host, the admin host in
| HOST_MODE=split, or a reseller's active custom domain). The two values below are only the
| package's fallbacks for code that runs outside a request.
|
*/

return [

    'enabled' => (bool) env('PASSKEYS_ENABLED', true),

    'relying_party_id' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',

    'allowed_origins' => [
        rtrim((string) config('app.url'), '/'),
    ],

    // Derives the opaque WebAuthn user handle. Set it explicitly before rotating APP_KEY (stored
    // passkeys keep working either way: the handle saved with each passkey is the one checked).
    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),

    // Milliseconds the browser gives the person to finish the passkey prompt.
    'timeout' => (int) env('PASSKEYS_TIMEOUT', 60000),

    // Seconds a server-side challenge stays valid (single use). Kept a little above the browser
    // timeout so a slow but legitimate prompt still completes.
    'challenge_ttl' => (int) env('PASSKEYS_CHALLENGE_TTL', 120),

    // Failed passkey sign-ins allowed per IP before a short lockout (on top of throttle:auth-login).
    'max_failures' => 10,
    'failure_window' => 60,

    // Passkeys one account may hold.
    'max_per_user' => 20,

    'guard' => 'web',

    'redirect' => '/dashboard',

];
