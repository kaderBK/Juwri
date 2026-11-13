<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Paths whose legacy names are intentional and must stay.
 *
 * Paths are globs relative to core/. A single '*' stays inside one path segment,
 * '**' crosses directory separators. Rule '*' means every rule; otherwise list
 * the rule names returned by LegacyNamingGuardTest::rules().
 *
 * phpstan-baseline.neon is deliberately absent: it must be fixed, not exempted.
 */
return [
    [
        'path' => 'app/Core/Support/LegacyStoreKeys.php',
        'rules' => ['*'],
        'reason' => 'Central translation table that reads old store keys while data is migrated.',
    ],
    [
        'path' => 'app/Core/Http/Middleware/AcceptLegacyStoreKeys.php',
        'rules' => ['*'],
        'reason' => 'Middleware that accepts old store keys from in-flight clients during the rename window.',
    ],
    [
        'path' => 'app/Core/Support/legacy_class_aliases.php',
        'rules' => ['*'],
        'reason' => 'Class alias map that keeps old class names autoloadable for extensions.',
    ],
    [
        'path' => 'app/Modules/LegacyCompat/**',
        'rules' => ['*'],
        'reason' => 'J52b compatibility module (not created yet) that will own all legacy shims.',
    ],
    [
        'path' => 'app/Modules/Channels/Services/Aggregators/**',
        'rules' => ['*'],
        'reason' => 'Swiggy/Zomato partner API vocabulary uses restaurant/post terms in their own payloads.',
    ],
    [
        'path' => 'config/directory.php',
        'rules' => ['*'],
        'reason' => 'Google Places directory place types use the upstream restaurant vocabulary.',
    ],
    [
        'path' => 'database/migration-checksums*.json',
        'rules' => ['*'],
        'reason' => 'Checksums of already-applied migrations must keep their historical names.',
    ],
    [
        'path' => 'database/migrations/**',
        'rules' => ['*'],
        'reason' => 'Applied migrations keep their historical names; renames get new *_rename_* migrations.',
    ],
    [
        'path' => 'app/**/Database/Migrations/**',
        'rules' => ['*'],
        'reason' => 'Module migrations already applied keep their historical names; renames get new *_rename_* migrations.',
    ],
    [
        'path' => 'ee/**/Database/Migrations/**',
        'rules' => ['*'],
        'reason' => 'Enterprise module migrations keep their historical names; renames get new *_rename_* migrations.',
    ],
    [
        'path' => 'tests/Feature/Database/StoreRenameSettingsMigrationTest.php',
        'rules' => ['*'],
        'reason' => 'Seeds the old store/platform setting names to prove the J52 data migrations move them.',
    ],
    [
        'path' => '**/tests/Modules/Reseller/ResellerPresetKeyRenameMigrationTest.php',
        'rules' => ['*'],
        'reason' => 'Seeds the old preset option names to prove the J52 data migration moves them.',
    ],
    [
        'path' => 'tests/Architecture/legacy-naming/**',
        'rules' => ['*'],
        'reason' => 'The rename map, allowlist and this guard necessarily contain every legacy name.',
    ],
];
