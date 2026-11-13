{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._env', ['name' => 'paylink_sandbox', 'help' => ___('Sandbox uses restpilot.paylink.sa; live uses restapi.paylink.sa.')])
<x-ui.field name="paylink_api_id" :label="___('API ID').' *'" :value="$settings->paylink_api_id ?? null" autocomplete="off" />
<x-ui.field name="paylink_secret_key" :label="___('Secret Key').' *'" :value="$settings->paylink_secret_key ?? null" autocomplete="off" />
@include('admin.billing.gateways._url', ['label' => ___('Callback / Webhook URL'), 'url' => route('webhook', 'paylink'), 'help' => ___('Paylink redirects here after payment; we re-verify via GetInvoice before settling.')])

<p class="ui-section-title">{{ ___('Partner API (programmatic merchant onboarding)') }}</p>
<p class="ui-small ui-text-2">{{ ___('Platform-level partner credentials issued by Paylink. Required for the "Get paid with Paylink" onboarding wizard. Enable the feature in Feature Controls (paylink_partner).') }}</p>
@include('admin.billing.gateways._env', ['name' => 'paylink_partner_sandbox', 'label' => ___('Partner Environment')])
<x-ui.field name="paylink_partner_api_id" :label="___('Partner API ID')" :value="$settings->paylink_partner_api_id ?? null" autocomplete="off" />
<x-ui.field name="paylink_partner_secret" :label="___('Partner Secret')" :value="$settings->paylink_partner_secret ?? null" autocomplete="off" />
@include('admin.billing.gateways._url', ['label' => ___('Partner Activation Webhook URL'), 'url' => route('paylink.partner.webhook')])
