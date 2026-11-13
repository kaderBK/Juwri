{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._env', ['name' => 'noon_sandbox', 'help' => ___('Sandbox uses api-test.noonpayments.com; live uses api.noonpayments.com.')])
<x-ui.field name="noon_business_id" :label="___('Business ID').' *'" :value="$settings->noon_business_id ?? null" autocomplete="off" />
<x-ui.field name="noon_application_id" :label="___('Application ID').' *'" :value="$settings->noon_application_id ?? null" autocomplete="off" />
<x-ui.field name="noon_api_key" :label="___('API Key').' *'" :value="$settings->noon_api_key ?? null" autocomplete="off" />
<x-ui.field name="noon_webhook_secret" :label="___('Webhook Secret')" :value="$settings->noon_webhook_secret ?? null" autocomplete="off"
            :help="___('From noon Merchant Portal -> Payment Settings -> Webhook. Until this is set, webhook callbacks are ignored.')" />
@include('admin.billing.gateways._url', ['label' => ___('Webhook URL'), 'url' => route('webhook', 'noon'), 'help' => ___('noon notifies here after payment; we re-verify via GET order before settling.')])
