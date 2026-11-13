<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * RB-10 — figures shown on the public /pricing page next to the plan catalogue.
 * Source of truth: the internal pricing document. Plan prices themselves come from the
 * admin plan catalogue, not from here. Add-on prices are entered in SAR (USD x 3.75 where USD-priced).
 */
return [
    'currency' => 'SAR',
    'addons' => [
        // key => [price in SAR per month, unit]; phone agent is Cloud-only, $149 incl. 600 minutes (PRICING.md §8a).
        'phone_agent' => ['sar' => 559, 'usd' => 149, 'unit' => 'location', 'minutes' => (int) env('VOICE_STORE_MONTHLY_MINUTES', 600)],
        'zatca_signing' => ['sar' => 19, 'unit' => 'store'],
        'delivery_relay' => ['sar' => 29, 'unit' => 'store'],
    ],
];
