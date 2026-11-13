{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._source', ['href' => 'https://vendors.paddle.com/authentication-v2'])
<x-ui.field type="select" name="paddle_sandbox_mode" :label="___('Sandbox Mode')" :value="$settings->paddle_sandbox_mode ?? null" :options="['Yes' => ___('Enable'), 'No' => ___('Disable')]" />
<x-ui.field name="paddle_api_key" :label="___('API Key').' *'" :value="$settings->paddle_api_key ?? null" autocomplete="off" />
<x-ui.field name="paddle_webhook_secret" :label="___('Webhook Secret').' *'" :value="$settings->paddle_webhook_secret ?? null" autocomplete="off" />
@include('admin.billing.gateways._url', ['label' => ___('Webhook URL'), 'url' => url('webhook/paddle'), 'help' => ___('Add this URL as a webhook destination in your Paddle dashboard.')])
<p class="ui-small ui-text-2">{{ ___('Paddle is a merchant of record: it calculates and remits taxes itself, so local tax rules are not applied to Paddle checkouts.') }}</p>
