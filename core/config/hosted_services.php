<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * juwri-hosted services (delivery-app relay, ZATCA signing, AI credits, messaging, App Store downloads, updates) —
 * owner 2026-10-05, "Protecting the Community limits without leaving the AGPL".
 *
 * INSTALLATION side (every edition): where the services live. The app never calls them on its own (no phone-home):
 * only when the owner connects a service (Juwri\Core\Services\HostedServices\HostedServiceClient, artisan
 * juwri:services:connect). The install ID + secret it gets back are kept in the platform settings
 * (juwri_services_install_id / juwri_services_install_secret, encrypted).
 *
 * SERVICE side (used only by juwri Cloud, the operator's Platform install): how a connecting installation is checked.
 */
return [
    'url' => env('JUWRI_SERVICES_URL', 'https://services.juwri.com'),
    'timeout' => (int) env('JUWRI_SERVICES_TIMEOUT', 15),

    // Service side: read-only copies of JUWRI LTD's issued-licence register (JSON Lines from the licence signing tool)
    // and of the shared revocation list. Never any key material. Unset or unreadable → licensed installations are
    // refused with 503 (fail closed); Community installations still work.
    'register_path' => env('JUWRI_REGISTER_PATH'),
    'revocations_path' => env('JUWRI_REVOCATIONS_PATH'),
    // Same 14-day grace as the in-app licence check.
    'grace_days' => 14,
    // Signed requests: accepted clock difference in seconds (a nonce is remembered for twice as long).
    'replay_window' => 300,
    // New install IDs per client IP and hour (connecting needs no account; this limits abuse).
    'register_per_hour' => (int) env('JUWRI_HOSTED_REGISTER_PER_HOUR', 10),
    // Where `artisan hosted:limit-holds` writes the licences seen past their licensed branch count (read by the
    // package server as JUWRI_LIMIT_HOLDS_PATH).
    'limit_holds_path' => env('JUWRI_LIMIT_HOLDS_PATH'),

    // STOP-list hashes (hosted messaging): set this to the CURRENT APP_KEY before rotating APP_KEY, or the list is lost
    'optout_hash_key' => env('JUWRI_HOSTED_OPTOUT_KEY'),

    // App Store downloads (juwri side): the signing key of the short-lived download URLs (32+ characters, config
    // only — never stored, returned or logged), how long a link lives, and the disk holding the packages
    'download_signing_key' => env('JUWRI_HOSTED_DOWNLOAD_KEY'),
    'download_ttl' => (int) env('JUWRI_HOSTED_DOWNLOAD_TTL', 300),
    'appstore_disk' => env('JUWRI_HOSTED_APPSTORE_DISK', 'local'),

    // Delivery-app partner relay. Installation side: CHANNELS_PARTNER_TRANSPORT=juwri_relay sends the partner-credential
    // connectors through juwri (default: direct). juwri side: nothing here — partner accounts stay in services.* config.
    'partner_transport' => env('CHANNELS_PARTNER_TRANSPORT', 'direct'),

    // Update server (juwri side): the disk holding release packages (links are signed with download_signing_key)
    'updates_disk' => env('JUWRI_HOSTED_UPDATES_DISK', 'local'),

    // AI credits proxy (juwri side): the most tokens one call may ask for
    'ai_max_tokens' => (int) env('JUWRI_HOSTED_AI_MAX_TOKENS', 2000),
];
