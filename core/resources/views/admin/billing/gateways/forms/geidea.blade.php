{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._env', ['name' => 'geidea_sandbox', 'help' => ___('Uses api.ksamerchant.geidea.net for both test and live (per-account).')])
<x-ui.field name="geidea_merchant_public_key" :label="___('Merchant Public Key').' *'" :value="$settings->geidea_merchant_public_key ?? null" autocomplete="off" />
<x-ui.field name="geidea_api_password" :label="___('API Password').' *'" :value="$settings->geidea_api_password ?? null" autocomplete="off" />
@include('admin.billing.gateways._url', ['label' => ___('Callback / Webhook URL'), 'url' => route('webhook', 'geidea'), 'help' => ___('Geidea POSTs here after payment; we verify the HMAC signature before settling.')])
