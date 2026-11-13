<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P6-09 V-11: phone-agent limits. Per-call limits stop toll fraud / runaway calls; the store cap bounds
 * monthly telephony spend. Past any limit the caller is handed off to staff, never silently dropped.
 */
return [
    // Max length of one call, in minutes.
    'max_call_minutes' => (int) env('VOICE_MAX_CALL_MINUTES', 8),
    // Max caller turns the agent answers in one call.
    'max_turns' => (int) env('VOICE_MAX_TURNS', 20),
    // Max calls open at once on one line.
    'max_concurrent' => (int) env('VOICE_MAX_CONCURRENT', 3),
    // Monthly minutes per store when the owner set no hard cap on the line (add-on includes 600).
    'store_monthly_minutes' => (int) env('VOICE_STORE_MONTHLY_MINUTES', 600),

    /*
     * P6-09 V-12: cost ledger + plan (spec §11: $149 / location / month incl. 600 minutes, then $0.20 / min).
     * cost_per_minute = our all-in serving cost estimate (telephony + STT/TTS + AI), recorded per call.
     */
    'plan_price' => (float) env('VOICE_PLAN_PRICE', 149),
    'plan_included_minutes' => (int) env('VOICE_PLAN_INCLUDED_MINUTES', 600),
    'overage_per_minute' => (float) env('VOICE_OVERAGE_PER_MINUTE', 0.20),
    'cost_per_minute' => (float) env('VOICE_COST_PER_MINUTE', 0.12),
    'cost_currency' => env('VOICE_COST_CURRENCY', 'USD'),

    /*
     * P6-09 V-15: every phone order is paid by the hosted pay link (SMS to the caller) and reaches the kitchen only
     * after the payment succeeds. Unpaid when the window ends → cancelled (orders:expire-hosted-payments).
     */
    'require_pay_link' => (bool) env('VOICE_REQUIRE_PAY_LINK', true),
    'pay_link_minutes' => (int) env('VOICE_PAY_LINK_MINUTES', 30),

    // P6-09 V-18: filesystem disk for recorded call audio (store-data transfer/delete uses it too).
    'recordings_disk' => env('VOICE_RECORDINGS_DISK', 'local'),
];
