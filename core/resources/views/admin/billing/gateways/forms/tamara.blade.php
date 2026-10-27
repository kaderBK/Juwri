{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._env', ['name' => 'tamara_sandbox', 'help' => ___('Sandbox uses api-sandbox.tamara.co; live uses api.tamara.co.')])
<x-ui.field name="tamara_api_token" :label="___('API Token').' *'" :value="$settings->tamara_api_token ?? null" autocomplete="off" :help="___('Bearer token used to create checkout sessions and capture orders.')" />
<x-ui.field name="tamara_notification_token" :label="___('Notification Token').' *'" :value="$settings->tamara_notification_token ?? null" autocomplete="off" :help="___('Used to verify the HS256-signed webhook (tamaraToken JWT).')" />
@include('admin.billing.gateways._url', ['label' => ___('Notification / Webhook URL'), 'url' => route('webhook', 'tamara'), 'help' => ___('Register this as the notification URL in the Tamara merchant portal; we authorise + capture before settling.')])
