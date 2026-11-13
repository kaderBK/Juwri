<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Merchant-of-record gateways. These providers are the legal seller: they
 * calculate, collect, and remit tax themselves, so local Tax records must NOT
 * be applied to their checkouts (double-tax), and they may only be offered on
 * platform-billing surfaces (plans, add-ons, Studio) — never restaurant order
 * checkout or per-restaurant BYOK (physical goods are prohibited by these
 * providers' terms).
 */
return [
    'folders' => [
        'paddle',
        'lemon_squeezy',
    ],
];
