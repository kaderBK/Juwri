{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._env', ['name' => 'gocardless_sandbox', 'help' => ___('Sandbox uses api-sandbox.gocardless.com; live uses api.gocardless.com.')])
<x-ui.field name="gocardless_access_token" :label="___('Access Token').' *'" :value="$settings->gocardless_access_token ?? null" autocomplete="off" />
<x-ui.field name="gocardless_webhook_secret" :label="___('Webhook Secret').' *'" :value="$settings->gocardless_webhook_secret ?? null" autocomplete="off" :help="___('Per-endpoint secret used to verify the Webhook-Signature (HMAC-SHA256) of each event.')" />
@include('admin.billing.gateways._url', ['label' => ___('Webhook URL'), 'url' => route('webhook', 'gocardless'), 'help' => ___('Add this endpoint in your GoCardless dashboard. Bank debit is asynchronous — plans activate on the payments.confirmed event, not at checkout return.')])
<x-ui.banner tone="info">{{ ___('Plan billing only (SaaS subscriptions). Supported currencies: GBP, EUR, USD, CAD, AUD, NZD, SEK, DKK. SAR is not supported.') }}</x-ui.banner>
