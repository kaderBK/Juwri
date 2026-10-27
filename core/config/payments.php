<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    /*
    |--------------------------------------------------------------------------
    | Payment sandboxes (Phase 4 item 18)
    |--------------------------------------------------------------------------
    |
    | Stripe, Tap, Moyasar and PayPal accept a provider TEST account (test keys
    | sk_test_… / PayPal sandbox mode) only while this flag is on. Off (the
    | default): a test key or sandbox mode is treated as "not configured" — no
    | payment starts on it — and a provider notification from a test account is
    | refused, so a store can never take "payments" that move no money.
    | Switch it on for a staging or demo install only.
    |
    */
    'sandbox' => [
        'enabled' => (bool) env('PAYMENTS_SANDBOX_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deferred gateways (J51a: only Stripe and PayPal at launch)
    |--------------------------------------------------------------------------
    |
    | Tap and Moyasar are not offered at launch. While `show_deferred_gateways`
    | is off (the default) they are hidden from the platform gateway list and
    | their admin edit/save is refused, so a store can never enable them by
    | hand. Switch the flag on for a staging or demo install once they are
    | approved for sale.
    |
    */
    'show_deferred_gateways' => (bool) env('PAYMENTS_SHOW_DEFERRED_GATEWAYS', false),
    'deferred_gateways' => ['tap', 'moyasar'],

    /*
    |--------------------------------------------------------------------------
    | Payments health watch (P6-93, `payments:watch`, every 5 minutes)
    |--------------------------------------------------------------------------
    |
    | State-based alerts (admins via AlertDispatcher; a single store's finding
    | also e-mails that store's owner). Each finding alerts at most once per
    | cooldown. Runbook: resources/docs/internal/deploy-runbook.md.
    |
    */
    'watch' => [
        'cooldown_minutes' => (int) env('PAYMENTS_WATCH_COOLDOWN_MINUTES', 60),
        // Gateway payments with no confirmation after this long, and how many make an alert.
        'stuck_after_minutes' => (int) env('PAYMENTS_WATCH_STUCK_MINUTES', 30),
        'stuck_min_count' => (int) env('PAYMENTS_WATCH_STUCK_MIN_COUNT', 3),
        'stuck_lookback_hours' => 24,
        // Slow by design (bank transfer, kiosk cash, direct debit, manual): never "stuck".
        'slow_gateways' => ['wire_transfer', 'wallet_transfer', 'store_transfer', 'manual', 'cash', 'stub', 'gocardless', 'fawry', 'check'],
        'webhook_rejections_per_hour' => (int) env('PAYMENTS_WATCH_REJECTIONS_PER_HOUR', 20),
        'webhook_rejections_per_hour_store' => (int) env('PAYMENTS_WATCH_REJECTIONS_PER_HOUR_STORE', 5),
        // A refund with no provider answer (RefundGuard keeps it pending) after this long; refused refunds this recent.
        'refund_pending_minutes' => 15,
        'refund_failed_lookback_minutes' => 60,
        'ledger_every_minutes' => 60,
        'payout_pending_hours' => (int) env('PAYMENTS_WATCH_PAYOUT_PENDING_HOURS', 72),
        'outbox_backlog_minutes' => (int) env('PAYMENTS_WATCH_OUTBOX_BACKLOG_MINUTES', 15),
    ],

];
