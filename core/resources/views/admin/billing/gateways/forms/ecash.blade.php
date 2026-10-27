{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.field name="ecash_merchant_id" :label="___('Merchant ID').' *'" :value="$settings->ecash_merchant_id ?? null" autocomplete="off" />
<x-ui.field name="ecash_terminal_id" :label="___('Terminal ID')" :value="$settings->ecash_terminal_id ?? null" autocomplete="off" />
<x-ui.field name="ecash_api_key" :label="___('API Key').' *'" :value="$settings->ecash_api_key ?? null" autocomplete="off" :help="___('Kept server-side; never exposed to the browser at checkout.')" />
@include('admin.billing.gateways._url', ['label' => ___('Callback / Webhook URL'), 'url' => route('webhook', 'ecash'), 'help' => ___('eCash notifies here after payment; we re-verify server-side before settling.')])
