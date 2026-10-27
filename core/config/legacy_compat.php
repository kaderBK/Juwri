<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
| J52b LegacyCompat switch. OFF by default: a fresh install registers no legacy route and keeps J52a (no aliases).
| Only the old-platform data migration turns it on for that one install (LEGACYCOMPAT-SPEC §2, §5).
*/
return [
    // OPEN: the migration plan names only the config key; the env var name is a proposal (LEGACYCOMPAT-SPEC §2).
    // (bool) would read the string "false" as true: parse it explicitly so the switch stays off.
    'enabled' => filter_var(env('LEGACY_COMPAT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // The old platform's own hosts (plan §3, Q13 open), comma-separated, e.g. its main domain. While the module is on
    // each one serves the path-form storefront like the storefront host (`/{slug}`, old printed links), its imported
    // `{label}.<host>` store subdomains resolve to their store, and slugs that newer paths shadow are served there
    // first (legacy_slug_conflicts). Empty (default) = no extra host. Never a code default: a configured value only.
    'hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('LEGACY_COMPAT_HOSTS', ''))), static fn (string $v): bool => $v !== '')),

    // M6 media copy: from_disk/to_disk are disk names from config/filesystems.php; manifest is an absolute local path.
    // All three default to null, so nothing is read or written until the migration sets them.
    'media' => [
        'from_disk' => env('LEGACY_COMPAT_MEDIA_FROM_DISK') ?: null,
        'to_disk' => env('LEGACY_COMPAT_MEDIA_TO_DISK') ?: null,
        'manifest' => env('LEGACY_COMPAT_MEDIA_MANIFEST') ?: null,
    ],

    // Old printed-QR "qr-id" passphrases (plan §5.2: two in circulation), comma-separated. Never hard-coded: the
    // migration writes them for the one migrated install. Empty = no old qr-id is decoded.
    'qr_passphrases' => array_values(array_filter(array_map('trim', explode(',', (string) env('LEGACY_COMPAT_QR_PASSPHRASES', ''))), static fn (string $v): bool => $v !== '')),

    // Old app key(s) that minted the printed hotel-room tokens (plan §5.3), each stored ENCRYPTED with this install's
    // APP_KEY (Crypt::encryptString), comma-separated. Decrypted only to verify a token; mint always uses APP_KEY.
    'room_token_legacy_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('LEGACY_COMPAT_ROOM_TOKEN_KEYS', ''))), static fn (string $v): bool => $v !== '')),

    // J56(a): old-key room tokens are accepted only BEFORE this instant: an absolute date `Y-m-d` (00:00 in the app
    // timezone) or ISO-8601 date-time. Empty (default), relative ("+6 months") or anything else = old-key tokens
    // refused: it must be set at cut-over (cut-over + 6 months per J56). Current-key tokens are unaffected.
    'room_token_legacy_until' => (string) env('LEGACY_COMPAT_ROOM_KEY_UNTIL', ''),

    // Google Wallet passes already saved on phones (plan §5.1, R8, Q11 open): stores with id <= max_store_id (the
    // imported range) mint and update passes under the source issuer id and id shape
    // `{issuer_id}.{class_prefix}{storeId}{object_separator}{subjectId}`. Empty issuer or max_store_id 0 (defaults) =
    // off: every store uses the platform ids. Malformed values are treated as unset (fail closed).
    'google_wallet' => [
        'issuer_id' => (string) env('LEGACY_COMPAT_WALLET_ISSUER_ID', ''),
        'max_store_id' => (int) env('LEGACY_COMPAT_WALLET_MAX_STORE_ID', 0),
        'loyalty_class_prefix' => (string) env('LEGACY_COMPAT_WALLET_LOYALTY_PREFIX', 'loy'),
        'gift_card_class_prefix' => (string) env('LEGACY_COMPAT_WALLET_GIFT_CARD_PREFIX', 'gc'),
        'object_separator' => (string) env('LEGACY_COMPAT_WALLET_OBJECT_SEPARATOR', 'c'),
    ],

    // J62 hard gate: false until the owner has signed off the hands-on demo, the feature parity matrix and the SEO
    // parity report. `legacy:cutover-check` fails while it is false; no production dry run, freeze or DNS step before.
    'cutover' => [
        'owner_signoff' => filter_var(env('LEGACY_CUTOVER_OWNER_SIGNOFF', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    | SEO parity of the migrated install (read only while `enabled` is on). Every value is an install setting; nothing
    | here names a domain or brand, and an empty value changes nothing.
    |
    | locale_default / locales / locale_prefix: the language set the old site served. `php artisan legacy:seo-locales`
    |   applies them to the platform settings (lang, include_language_code) and the language rows (active); with
    |   --check it only reports. locale_default is served at the root (no prefix), every other listed language under
    |   /{code}/, and the hreflang set is the listed languages plus x-default.
    | title_parity: pages whose title copy differs from the old site's (contact, one comparison) use the old titles.
    | canonical_redirect: 301 www.<app host> and plain http to https://<app host> (the app.url host only; store and
    |   reseller domains are never touched; /.well-known stays on http for certificate checks).
    */
    'seo' => [
        'locale_default' => strtolower(trim((string) env('LEGACY_COMPAT_SEO_LOCALE_DEFAULT', ''))),
        'locales' => array_values(array_filter(array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', (string) env('LEGACY_COMPAT_SEO_LOCALES', ''))), static fn (string $v): bool => $v !== '')),
        'locale_prefix' => filter_var(env('LEGACY_COMPAT_SEO_LOCALE_PREFIX', true), FILTER_VALIDATE_BOOLEAN),
        'title_parity' => filter_var(env('LEGACY_COMPAT_SEO_TITLE_PARITY', true), FILTER_VALIDATE_BOOLEAN),
        'canonical_redirect' => filter_var(env('LEGACY_COMPAT_SEO_CANONICAL_REDIRECT', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    | One-off import of the old platform's database into this install (`php artisan legacy:import`). Runs only on the
    | migration host at cut-over: it refuses unless BOTH `enabled` above and `import.enabled` here are on, the source
    | connection is named explicitly, and the target tables are empty. Nothing here has a default host or key.
    */
    'import' => [
        'enabled' => filter_var(env('LEGACY_IMPORT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        // J60: the host this install serves (must equal the host of APP_URL; required, no default). The import and the
        // media commands refuse unless it is set and matches.
        'target_host' => (string) env('LEGACY_IMPORT_TARGET_HOST', ''),

        // J60: fresh-install hosts that must never receive migrated data. The import refuses when the target
        // install's own host (the host of APP_URL) is one of them, or a sub-domain of one. The built-in host is
        // always forbidden, even when this list is emptied (fail closed): an emptied list never opens a fresh
        // install to migrated data.
        'forbidden_target_hosts' => array_values(array_filter(array_map(
            static fn (string $v): string => strtolower(trim($v)),
            explode(',', (string) env('LEGACY_IMPORT_FORBIDDEN_TARGET_HOSTS', 'juwri.com')),
        ), static fn (string $v): bool => $v !== '')),

        // A connection built only from env (no default host); `--source=legacy_import_source` selects it. Any other
        // connection in config/database.php may be named with --source instead.
        'connection' => [
            'driver' => (string) env('LEGACY_IMPORT_DB_DRIVER', 'mysql'),
            'host' => env('LEGACY_IMPORT_DB_HOST'),
            'port' => env('LEGACY_IMPORT_DB_PORT'),
            'database' => env('LEGACY_IMPORT_DB_DATABASE'),
            'username' => env('LEGACY_IMPORT_DB_USERNAME'),
            'password' => env('LEGACY_IMPORT_DB_PASSWORD'),
            'charset' => (string) env('LEGACY_IMPORT_DB_CHARSET', 'utf8mb4'),
            'collation' => (string) env('LEGACY_IMPORT_DB_COLLATION', 'utf8mb4_unicode_ci'),
            // Table prefix of the source schema (the old platform names every table `<prefix><name>`): table names are
            // read and reported WITHOUT it. Empty = unprefixed tables.
            'prefix' => (string) env('LEGACY_IMPORT_DB_PREFIX', ''),
            'strict' => false,
            // TIMESTAMP columns are read as UTC instants (session zone UTC); DATETIME columns carry no zone and are
            // converted from source_timezone below (SourceReader::utcSession()).
            'timezone' => '+00:00',
        ],

        // The source server's time zone (plan Q4). Required: source DATETIME values have no zone and are converted to
        // UTC with it; the import refuses to run while it is empty (never guessed).
        'source_timezone' => (string) env('LEGACY_IMPORT_SOURCE_TIMEZONE', ''),

        // The source install's app key (`base64:…`), used ONLY to decrypt per-store gateway secrets that the source
        // stored encrypted; they are re-encrypted with this install's key. Set at cut-over, never logged, never in git.
        // Empty = encrypted source secrets are not imported (reported; the gateway stays off).
        'source_app_key' => (string) env('LEGACY_IMPORT_SOURCE_APP_KEY', ''),
        'source_cipher' => (string) env('LEGACY_IMPORT_SOURCE_CIPHER', 'AES-256-CBC'),

        // J51a: store gateways that may stay switched on after import (deferred gateways are always off).
        'enabled_gateways' => array_values(array_filter(array_map('trim', explode(',', (string) env('LEGACY_IMPORT_ENABLED_GATEWAYS', 'stripe,paypal'))), static fn (string $v): bool => $v !== '')),

        // J59c: source plan id => plan id of this install, or `free` ("3:12,4:13,5:free"). A source plan not listed (or
        // naming a plan this install does not have) lands on the free plan and is reported: never guessed.
        'plan_map' => (static function (string $raw): array {
            $map = [];
            foreach (explode(',', $raw) as $pair) {
                $parts = array_map('trim', explode(':', $pair, 2));
                if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                    $map[$parts[0]] = $parts[1];
                }
            }

            return $map;
        })((string) env('LEGACY_IMPORT_PLAN_MAP', '')),

        // Imported store domains stay unverified until this install's DNS proof passes; while pending they are
        // held this many hours (default 90 days) before another store may request the same host.
        'domain_grace_hours' => max(0, (int) env('LEGACY_IMPORT_DOMAIN_GRACE_HOURS', 2160)),

        // Rows per read chunk (platform tables and per-store child tables).
        'chunk' => max(50, (int) env('LEGACY_IMPORT_CHUNK', 500)),

        // Target sequences / auto-increments are moved to max(id) + this gap after a real (not dry) run (plan §1.1 rule 1).
        'sequence_gap' => max(1, (int) env('LEGACY_IMPORT_SEQUENCE_GAP', 1000)),
    ],
];
