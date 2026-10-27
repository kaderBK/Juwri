{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._source', ['href' => 'https://www.mollie.com/dashboard'])
<x-ui.field name="mollie_api_key" :label="___('API Key').' *'" :value="$settings->mollie_api_key ?? null" autocomplete="off" />
<x-ui.switch name="mollie_webhook_enabled" off="0" :checked="(string) ($settings->mollie_webhook_enabled ?? '1') === '1'"
             :label="___('Send Mollie a webhook (server-side settlement)')" :description="___('Off means payments settle only when the buyer returns to the browser. Leave on.')" />
@include('admin.billing.gateways._url', ['label' => ___('Webhook URL'), 'url' => url('webhook/mollie'), 'help' => ___('Sent with every payment. Mollie must be able to reach it from the public internet.')])
<x-ui.field type="url" name="mollie_webhook_url" :label="___('Webhook URL override')" :value="$settings->mollie_webhook_url ?? null" :placeholder="url('webhook/mollie')"
            :help="___('Optional. Use a public tunnel or a separate public hostname when this site is not reachable from the internet.')" />
<div class="ui-field">
    <span class="ui-field__label">{{ ___('Last webhook received') }}</span>
    @if (! empty($settings->mollie_webhook_last_at))
        <p class="ui-cluster"><x-ui.tag tone="success">{{ $settings->mollie_webhook_last_status ?? '' }}</x-ui.tag> <small class="ui-text-2">{{ $settings->mollie_webhook_last_at }}</small></p>
    @else
        <p class="ui-cluster"><x-ui.tag tone="warning">{{ ___('Never') }}</x-ui.tag> <small class="ui-text-2">{{ ___('No Mollie callback has arrived yet.') }}</small></p>
    @endif
</div>
