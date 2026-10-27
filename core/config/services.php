<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Modules\Reservations\Services\Rwg\Feeds\AvailabilityFeedBuilder;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\MerchantsFeedBuilder;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\ServicesFeedBuilder;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    // P6-249: bounce / complaint webhooks (POST /webhook/mail/{ses|mailgun|postmark|sendgrid}). A blank value
    // disables that provider's webhook (every request is refused) — see deploy/EMAIL.md.
    'mail_events' => [
        'ses_topic_arn' => env('SES_SNS_TOPIC_ARN'),
        'mailgun_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
        'postmark_user' => env('POSTMARK_WEBHOOK_USER'),
        'postmark_password' => env('POSTMARK_WEBHOOK_PASSWORD'),
        'sendgrid_public_key' => env('SENDGRID_WEBHOOK_PUBLIC_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Socialite sign-in providers. The callback URLs are fixed routes under
    // APP_URL; per-store keys can override them through the credential vault.
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('APP_URL').'/auth/facebook/callback',
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('APP_URL').'/auth/google/callback',
    ],

    // FEATURES-5 Track A — Sign in with Apple (customer social login). Platform
    // fallback keys come from env; per-restaurant BYOK overrides via the
    // Credential vault (feature='social', provider='apple'). client_secret is a
    // freshly-minted ES256 JWT (see AppleClientSecret), NOT a static string.
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => env('APP_URL').'/auth/apple/callback',
    ],

    // HungerStation Partner API. driver=fake (default) uses fixtures; 'real' uses the
    // OAuth2 client (P1). Per-tenant credentials are BYOK via Credential feature='hungerstation'.
    'hungerstation' => [
        'driver' => env('HS_DRIVER', 'fake'),
        'base_url' => env('HS_BASE_URL', 'https://api.hungerstation.com'),
        'auth_url' => env('HS_AUTH_URL', 'https://api.hungerstation.com/oauth/token'),
        'verify_signatures' => env('HS_VERIFY_SIGNATURES', true),
    ],

    // Foodics POS bridge. Platform-level OAuth app creds (a single Foodics app the
    // platform registers); owner connects each restaurant via OAuth. Per-restaurant
    // overrides may still be stored as Credential feature='foodics'. driver=fake uses
    // FakeFoodicsClient; 'real' hits the live API.
    'foodics' => [
        'driver' => env('FOODICS_DRIVER', 'fake'),
        'client_id' => env('FOODICS_CLIENT_ID'),
        'client_secret' => env('FOODICS_CLIENT_SECRET'),
    ],

    // ===== google-partner Lane A (GBP) — additive block, dark by default =====
    // Google Business Profile API. A SEPARATE OAuth client from the Socialite login
    // block above ('google') — different scope (business.manage, SENSITIVE), different
    // consent, different redirect. NEVER share tokens between the two. Platform-level
    // single OAuth app; each restaurant owner connects their OWN profile via OAuth and
    // the per-restaurant token lives encrypted in Credential feature='gbp'.
    // driver=fake (default) → FakeGbpClient, zero HTTP; 'real' → GbpClient once creds
    // exist (GbpClientFactory falls back to Fake when the class/token is absent).
    'gbp' => [
        'driver' => env('GBP_DRIVER', 'fake'),
        'client_id' => env('GBP_CLIENT_ID'),
        'client_secret' => env('GBP_CLIENT_SECRET'),
        // The controller always sends route('stores.gbp.oauth.callback') for both
        // authorize + token exchange so they can never drift; this env is the value the
        // owner registers in the Google console (must equal that route's production URL).
        'redirect' => env('GBP_REDIRECT', env('APP_URL').'/google-ordering/oauth/callback'),
    ],
    // ===== end google-partner Lane A =====

    // T3 aggregator hub. driver=fake (default) uses fixtures / in-memory; 'real' uses
    // the live client. Per-tenant creds are BYOK via Credential feature='deliverect'/'uber_eats'.
    'deliverect' => [
        'driver' => env('DELIVERECT_DRIVER', 'fake'),
    ],
    'uber_eats' => [
        'driver' => env('UBER_EATS_DRIVER', 'fake'),
        'base_url' => env('UBER_EATS_BASE_URL', 'https://api.uber.com'),
    ],

    // T4 courier dispatch (3rd-party last-mile). BYOK via Credential feature='uber_direct'/'doordash'.
    'uber_direct' => [
        'driver' => env('UBER_DIRECT_DRIVER', 'fake'),
        'base_url' => env('UBER_DIRECT_BASE_URL', 'https://api.uber.com'),
    ],
    'doordash' => [
        'driver' => env('DOORDASH_DRIVER', 'fake'),
        'base_url' => env('DOORDASH_BASE_URL', 'https://openapi.doordash.com'),
    ],

    /* PARITY-RUN T7 — India marketplaces. driver=fake (default) serves the mock
       clients, so the whole driver is exercisable without partner access; 'real'
       switches to the live client, which only exists once Zomato's developer
       program (BD-ACTIONS #11) and Swiggy's approved-POS-partner onboarding
       (BD-ACTIONS #12) grant it. Per-tenant credentials are BYOK via the
       Credential vault (feature='zomato'/'swiggy'), never these env values. */
    'zomato' => [
        'driver' => env('ZOMATO_DRIVER', 'fake'),
        'base_url' => env('ZOMATO_BASE_URL', 'https://partner-api.zomato.com'),
    ],
    'swiggy' => [
        'driver' => env('SWIGGY_DRIVER', 'fake'),
        'base_url' => env('SWIGGY_BASE_URL', 'https://partner-api.swiggy.com'),
    ],

    /* A6 — KSA marketplaces. driver=fake (default) serves the mock clients; 'real'
       switches to the live signed client, which only exists once Jahez portal access
       (BD-ACTIONS #7) and the Keeta test store (BD-ACTIONS #5) grant it. Until then,
       driver=real + BYOK creds surfaces an "unavailable" state (fail-loud) instead of a
       silent Fake fallback. Per-tenant credentials are BYOK via the Credential vault
       (feature='jahez'/'keeta'), never these env values. */
    'jahez' => [
        'driver' => env('JAHEZ_DRIVER', 'fake'),
        'base_url' => env('JAHEZ_BASE_URL', 'https://api.jahez.net'),
    ],
    'keeta' => [
        'driver' => env('KEETA_DRIVER', 'fake'),
        'base_url' => env('KEETA_BASE_URL', 'https://api.keeta.com'),
    ],

    /* ZATCA (KSA e-invoicing). RB-11: the live HTTP client is used only when ZATCA_LIVE=true; otherwise the in-memory
       fake answers (dev, tests, demos). ZATCA_ENV picks the FATOORA gateway: sandbox (developer portal, any OTP such as
       123456, no legal effect), simulation (real onboarding rehearsal, OTP from the Fatoora simulation portal) or
       production (real tax records). ZATCA_BASE_URL overrides the URL. */
    'zatca' => [
        'live' => (bool) env('ZATCA_LIVE', false),
        'environment' => env('ZATCA_ENV', 'sandbox'),
        'urls' => [
            'sandbox' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
            'simulation' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
            'production' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
        ],
        'base_url' => env('ZATCA_BASE_URL') ?: null,
        // Days before a production certificate expires that fiscal:zatca-check starts warning (renew in the portal first).
        'renew_warn_days' => (int) env('ZATCA_RENEW_WARN_DAYS', 45),
        // P6-150: folder of the ZATCA SDK the owner downloaded from the Fatoora portal (used by fiscal:zatca-sdk-validate only).
        'sdk_path' => env('ZATCA_SDK_PATH') ?: null,
    ],

    /* Mobile push (BYOK). PARITY-RUN T9 resolves these through the encrypted
       Credential vault first (feature='mobile_push', merchant row → platform
       default) and only falls back to these env values, so a self-hosted
       single-tenant install can still configure Firebase from .env.

       `project_id` alone is not enough for FCM HTTP v1 — that needs the whole
       service account, which belongs in the vault, never in .env. The legacy
       `server_key` lane is retired by Google but kept for installs that carry
       one. All empty → FcmPushSender safely no-ops. */
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID', ''),
        'server_key' => env('FCM_SERVER_KEY', ''),
    ],

    /* Google Wallet passes (loyalty cards + gift cards). DARK by default: with no
       issuer id and no service-account key, GoogleWalletService::enabled() returns
       false and every hook + storefront/dashboard UI no-ops — the pack ships
       inert until an owner wires it (RUNBOOK §7.3).

       - issuer_id: the platform's Google Wallet issuer account id (numeric string).
       - service_account_json: ABSOLUTE path to the issuer service-account key file
         (JSON). Never committed — lives outside the repo. Signs save-link JWTs
         (RS256) and authenticates the REST balance-sync (C3). A demo-mode issuer
         renders passes with a "[TEST ONLY]" banner for allow-listed test users —
         fine for a dark build; publishing access is a post-soak console request.
       - origins: optional extra comma-separated origins allowed on the save link
         (the store's own effective public origin is always added at build time via
         effective_public_url; this is only for additional platform hosts). */
    'google_wallet' => [
        'issuer_id' => env('GOOGLE_WALLET_ISSUER_ID', ''),
        'service_account_json' => env('GOOGLE_WALLET_SA_JSON', ''),
        'origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_WALLET_ORIGINS', ''))
        ))),
    ],

    /* ===== GOOGLE-PARTNER Lane C: Reserve with Google (reservations E2E) =====
       Additive block, C-owned per SPEC §9. DARK by default: with `enabled` off
       (RWG_ENABLED unset), RwgAuth returns 503 to every booking-server call, the
       feeds command writes nothing, and the RTU client no-ops — the whole surface
       ships inert until an owner wires Actions Center creds (RUNBOOK §7.2).

       - enabled: master switch AND kill-switch. Off = booking server 503, no feeds,
         no RTU. Flip on only after sandbox creds are set.
       - driver: fake|real. `fake` (default) is safe for tests/dark; `real` lets the
         feeds command attempt an SFTP upload and the RTU client attempt an HTTP push.
       - basic_user / basic_pass: HTTP Basic credentials Google sends on every
         booking-server request (issued in the Actions Center console; rotate ~6mo).
         Compared with hash_equals in the RwgAuth middleware.
       - service_account_json: ABSOLUTE path to the service-account key file used to
         sign RS256 JWTs for real-time updates (RTU). Empty = RTU no-ops.
       - rtu_endpoint / rtu_scope: Actions Center / Maps-Booking RTU target + OAuth
         scope for the service-account token (owner sets at onboarding).
       - sftp_*: daily feed drop target. Empty host = feeds are written to
         storage/app/rwg/ for inspection but NOT uploaded (dark).
       - feed_builders: RwgFeedContributor classes the feeds command runs. Lane C
         registers the reservations builders in C4; Lane D appends its waitlist
         builder here (additive; F0 resolves the merge). */
    'reserve_with_google' => [
        'enabled' => (bool) env('RWG_ENABLED', false),
        'driver' => env('RWG_DRIVER', 'fake'),
        'basic_user' => env('RWG_BASIC_USER', ''),
        'basic_pass' => env('RWG_BASIC_PASS', ''),
        'service_account_json' => env('RWG_SA_JSON', ''),
        'rtu_endpoint' => env('RWG_RTU_ENDPOINT', ''),
        'rtu_scope' => env('RWG_RTU_SCOPE', 'https://www.googleapis.com/auth/mapsbooking'),
        'sftp_host' => env('RWG_SFTP_HOST', ''),
        'sftp_port' => (int) env('RWG_SFTP_PORT', 22),
        'sftp_user' => env('RWG_SFTP_USER', ''),
        'sftp_key' => env('RWG_SFTP_KEY', ''),
        'sftp_path' => env('RWG_SFTP_PATH', '/'),
        'sftp_attempts' => (int) env('RWG_SFTP_ATTEMPTS', 3),
        'feed_shard_size' => (int) env('RWG_FEED_SHARD_SIZE', 20000),
        'feed_builders' => [
            MerchantsFeedBuilder::class,    // C4
            ServicesFeedBuilder::class,     // C4
            AvailabilityFeedBuilder::class, // C4
            // D5 waitlist services are NOT a separate contributor (the Services feed
            // is one file) — they are appended inside ServicesFeedBuilder::build()
            // via WaitlistServiceFeedBuilder (wired at F0).
        ],
    ],

    // P5-20 (ROADMAP 24 part 2) Google Ordering Redirect: Google links the diner OUT to the store's own ordering
    // page. We publish a Merchant / Service / Menu feed to the Actions Center SFTP drop.
    // - enabled: the platform kill-switch (default OFF; the nightly command is a dark no-op when off).
    // - driver:  `fake` (default) → build artifacts only, never open a socket; `real` → attempt the SFTP drop.
    // - sftp_*:  the drop target. Empty host = feeds are written to storage/app/google-ordering/ but NOT uploaded.
    // - feed_shard_size: max entries per shard (a merchant's entries always stay together).
    // - utm_*:   the attribution pair stamped on every action link.
    // The per-store owner switch is the store option `google_ordering_enabled` (default off).
    'google_ordering' => [
        'enabled' => (bool) env('GOOGLE_ORDERING_ENABLED', false),
        'driver' => env('GOOGLE_ORDERING_DRIVER', 'fake'),
        'sftp_host' => env('GOOGLE_ORDERING_SFTP_HOST', ''),
        'sftp_port' => (int) env('GOOGLE_ORDERING_SFTP_PORT', 22),
        'sftp_user' => env('GOOGLE_ORDERING_SFTP_USER', ''),
        'sftp_key' => env('GOOGLE_ORDERING_SFTP_KEY', ''),
        'sftp_path' => env('GOOGLE_ORDERING_SFTP_PATH', '/'),
        'sftp_attempts' => (int) env('GOOGLE_ORDERING_SFTP_ATTEMPTS', 3),
        'feed_shard_size' => (int) env('GOOGLE_ORDERING_FEED_SHARD_SIZE', 20000),
        'utm_source' => 'google',
        'utm_medium' => 'ordering_redirect',
    ],

    // P4-21 Odoo connector: a store's Odoo must be a public https address. A self-hoster whose Odoo lives on their own
    // LAN lists it EXACTLY here (host names and/or IPs, comma separated; no wildcards or ranges): ODOO_PRIVATE_HOSTS.
    // local / testing environments accept any address.
    'odoo' => [
        'private_hosts' => env('ODOO_PRIVATE_HOSTS', ''),
    ],

    // P6-09 voice telephony / bridge (Cloud only). ws_base is the public wss origin the carrier is told to
    // connect to (VoiceWebhookController::bridgeUrl); empty → derived from APP_URL. bridge_secret signs the
    // short-lived per-call bridge token (BridgeToken); empty → a key derived from APP_KEY. No value here is a
    // per-tenant secret: the token is minted per call and never stored.
    'telephony' => [
        'ws_base' => env('VOICE_BRIDGE_PUBLIC_URL'),
        'bridge_secret' => env('VOICE_BRIDGE_SECRET'),
    ],

    // P6-09 V-15: Telnyx account public key (base64 Ed25519) for webhook signatures; a store credential's
    // `public_key` overrides it.
    'telnyx' => [
        'public_key' => env('TELNYX_PUBLIC_KEY'),
    ],

];
