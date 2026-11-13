<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-13: editions. Community is the code under core/app. An edition (the Enterprise package) is an optional Composer
 * package found through its edition manifest (Juwri\Core\Editions\EditionPackages) and loaded by
 * Juwri\Core\Editions\EditionLoader; Community never names it. The edition registers nothing unless its offline
 * licence verifies (Ed25519, 14-day grace).
 */
return [
    'licence' => [
        // {"payload": base64url(JSON), "signature": base64url(Ed25519 detached signature over the payload bytes)}
        'path' => env('JUWRI_LICENCE_PATH', storage_path('app/licence/juwri.licence')),
        // The verifying public key is NOT configurable: it is a fixed file shipped with the edition package, so an
        // operator cannot point the check at a key of their own and self-sign a licence.
    ],
];
