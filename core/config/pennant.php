<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
| P1-07 (J25-5): Laravel Pennant backs the feature registry (Juwri\Core\Features\PlatformFeatures).
| The platform `admin_allow_*` switches are resolved live from platform_settings, so the in-memory
| `array` store is all the registry needs; nothing is persisted by Pennant itself (its own table would
| clash with the existing `features` catalog table). Per-account / per-store plan features (P1-13)
| will add a database store here under a different table name.
*/
return [
    'default' => 'array',

    'stores' => [
        'array' => [
            'driver' => 'array',
        ],
    ],
];
