{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Bug fix (Phase 4 item 18): Flutterwave had a gateway row but no admin form, so its edit page failed. --}}
@include('admin.billing.gateways._source', ['href' => 'https://dashboard.flutterwave.com'])
<x-ui.field name="flutterwave_secret_key" :label="___('Secret Key').' *'" :value="$settings->flutterwave_secret_key ?? null" autocomplete="off" />
<x-ui.field name="flutterwave_secret_hash" :label="___('Webhook Secret')" :value="$settings->flutterwave_secret_hash ?? null" autocomplete="off" />
@include('admin.billing.gateways._url', ['label' => ___('Webhook URL'), 'url' => route('webhook', 'flutterwave')])
