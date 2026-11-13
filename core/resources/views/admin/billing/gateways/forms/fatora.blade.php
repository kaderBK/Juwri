{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._env', ['name' => 'fatora_sandbox', 'help' => ___('Test vs live is the account mode; both use api.fatora.io.')])
<x-ui.field name="fatora_api_key" :label="___('API Key').' *'" :value="$settings->fatora_api_key ?? null" autocomplete="off" />
@include('admin.billing.gateways._url', ['label' => ___('Callback / Webhook URL'), 'url' => route('webhook', 'fatora'), 'help' => ___('Fatora redirects here after payment; we re-verify server-side before settling.')])
