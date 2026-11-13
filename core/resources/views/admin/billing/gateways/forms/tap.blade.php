{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Phase 4 item 18: Tap (MENA) — platform keys; store keys live in each store's payment settings. --}}
@include('admin.billing.gateways._source', ['href' => 'https://businesses.tap.company'])
<x-ui.field name="tap_secret_key" :label="___('Secret Key').' *'" :value="$settings->tap_secret_key ?? null" autocomplete="off"
            :help="___('Test keys (sk_test_…) work only while payment sandboxes are switched on for this installation.')" />
<x-ui.field name="tap_publishable_key" :label="___('Publishable Key')" :value="$settings->tap_publishable_key ?? null" autocomplete="off" />
@include('admin.billing.gateways._url', ['label' => ___('Webhook URL'), 'url' => route('webhook', 'tap'),
    'help' => ___('Tap signs each notification with your secret key; no separate webhook secret is needed.')])
