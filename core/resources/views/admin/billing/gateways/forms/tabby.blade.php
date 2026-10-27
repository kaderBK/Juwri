{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._env', ['name' => 'tabby_sandbox', 'help' => ___('Tabby keys select the environment; the API host is the same for test and live.')])
<x-ui.field name="tabby_secret_key" :label="___('Secret Key').' *'" :value="$settings->tabby_secret_key ?? null" autocomplete="off" />
<x-ui.field name="tabby_public_key" :label="___('Public Key').' *'" :value="$settings->tabby_public_key ?? null" autocomplete="off" />
<x-ui.field name="tabby_merchant_code" :label="___('Merchant Code').' *'" :value="$settings->tabby_merchant_code ?? null" autocomplete="off" />
@include('admin.billing.gateways._url', ['label' => ___('Webhook URL'), 'url' => route('webhook', 'tabby'), 'help' => ___('Register this URL with Tabby. Webhooks are unsigned — we verify a custom auth header (X-Tabby-Auth = your secret key) and re-fetch the payment before settling.')])
