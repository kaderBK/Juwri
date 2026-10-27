{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._env', ['name' => 'fawry_sandbox', 'help' => ___('Sandbox uses atfawry.fawrystaging.com; live uses atfawry.com. EGP only.')])
<x-ui.field name="fawry_merchant_code" :label="___('Merchant Code').' *'" :value="$settings->fawry_merchant_code ?? null" autocomplete="off" />
<x-ui.field name="fawry_security_key" :label="___('Security Key').' *'" :value="$settings->fawry_security_key ?? null" autocomplete="off" :help="___('Used to build the SHA-256 request/notification signatures. Keep it secret.')" />
@include('admin.billing.gateways._url', ['label' => ___('Server Notification (Webhook) URL'), 'url' => route('webhook', 'fawry'), 'help' => ___('Give this URL to Fawry ops during onboarding — they configure it as your Server-to-Server Notification V2 endpoint. We verify each notification signature before settling.')])
