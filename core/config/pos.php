<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Offline POS (the offline POS spec; owner answers 2026-10-08 (3)).
 */
return [
    'offline' => [
        // Q2: hours a till may sell offline after it last reached the server (store default; platform maximum)
        'default_hours' => (int) env('POS_OFFLINE_DEFAULT_HOURS', 12),
        'max_hours' => (int) env('POS_OFFLINE_MAX_HOURS', 24),
        // A till that does not reach the server for this many days wipes itself on its next start (its lease)
        'wipe_after_days' => (int) env('POS_OFFLINE_WIPE_AFTER_DAYS', 3),
        // Q5: days an unsynced record may wait before the till blocks new sales
        'retention_days' => (int) env('POS_OFFLINE_RETENTION_DAYS', 7),
        // Q3: offline card caps (platform maxima, minor units: SAR 250 / SAR 2,500); OFF until Q1 (provider) is answered
        'card_enabled' => (bool) env('POS_OFFLINE_CARD_ENABLED', false),
        'card_max_amount_minor' => (int) env('POS_OFFLINE_CARD_MAX_AMOUNT_MINOR', 25000),
        'card_max_total_minor' => (int) env('POS_OFFLINE_CARD_MAX_TOTAL_MINOR', 250000),
        // Q6 / OP-12: each till is its own ZATCA EGS unit and signs simplified invoices on the device. OFF until OP-0
        // (the owner confirming per-device EGS for offline B2C with ZATCA); while off every pos/offline/zatca route is 404.
        'zatca_device_signing' => (bool) env('POS_OFFLINE_ZATCA_DEVICE_SIGNING', false),
        // P6-94: fiscal:zatca-check per-till watch. FAIL levels are fixed (certificate <= 14 days, a pending invoice
        // older than 20 h of ZATCA's 24 h reporting window, a till unseen > 24 h holding pending invoices, any chain
        // gap / fork in the last chain_days); the WARN levels below are configurable.
        'zatca_watch' => [
            'pending_warn_hours' => (int) env('POS_OFFLINE_ZATCA_PENDING_WARN_HOURS', 12),
            'unseen_warn_hours' => (int) env('POS_OFFLINE_ZATCA_UNSEEN_WARN_HOURS', 12),
            'chain_days' => (int) env('POS_OFFLINE_ZATCA_CHAIN_DAYS', 7),
        ],
    ],
];
