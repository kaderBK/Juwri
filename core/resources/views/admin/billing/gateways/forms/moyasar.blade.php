{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Phase 4 item 18: Moyasar (KSA) — platform keys; store keys live in each store's payment settings. --}}
@include('admin.billing.gateways._source', ['href' => 'https://dashboard.moyasar.com'])
<x-ui.field name="moyasar_secret_key" :label="___('Secret Key').' *'" :value="$settings->moyasar_secret_key ?? null" autocomplete="off"
            :help="___('Test keys (sk_test_…) work only while payment sandboxes are switched on for this installation.')" />
<x-ui.field name="moyasar_publishable_key" :label="___('Publishable Key')" :value="$settings->moyasar_publishable_key ?? null" autocomplete="off" />
<x-ui.field name="moyasar_webhook_secret" :label="___('Webhook Secret').' *'" :value="$settings->moyasar_webhook_secret ?? null" autocomplete="off"
            :help="___('Paste the secret token you set on the Moyasar webhook. Notifications without it are refused.')" />
@include('admin.billing.gateways._url', ['label' => ___('Webhook URL'), 'url' => route('webhook', 'moyasar')])
