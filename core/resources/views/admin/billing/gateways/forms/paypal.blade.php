{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._source', ['href' => 'https://developer.paypal.com/developer/applications/create'])
<x-ui.field type="select" name="paypal_sandbox_mode" :label="___('Sandbox Mode')" :value="$settings->paypal_sandbox_mode ?? null" :options="['Yes' => ___('Enable'), 'No' => ___('Disable')]" />
<x-ui.field type="select" name="paypal_payment_mode" :label="___('Payment Mode')" :value="$settings->paypal_payment_mode ?? null"
            :options="['one_time' => ___('One Time'), 'recurring' => ___('Recurring'), 'both' => ___('Both')]" />
<x-ui.field name="paypal_api_client_id" :label="___('Client ID').' *'" :value="$settings->paypal_api_client_id ?? null" autocomplete="off" />
<x-ui.field name="paypal_api_secret" :label="___('Client Secret').' *'" :value="$settings->paypal_api_secret ?? null" autocomplete="off" />
<x-ui.field name="paypal_api_app_id" :label="___('APP ID').' *'" :value="$settings->paypal_api_app_id ?? null" autocomplete="off" />
{{-- WI-06a: when set, every PayPal notification must pass PayPal's verify-webhook-signature check. --}}
<x-ui.field name="paypal_webhook_id" :label="___('Webhook ID')" :value="$settings->paypal_webhook_id ?? null" autocomplete="off"
            :help="___('The ID PayPal shows for this webhook in your app settings. When set, every notification must pass PayPal\'s signature check; when empty, notifications are re-checked with PayPal before settling.')" />
@include('admin.billing.gateways._url', ['label' => ___('WebHook Url'), 'url' => url('webhook/'.$gateway->payment_folder),
    'helpHtml' => ___('Select the :EVENTS events for webhook.', ['EVENTS' => '<code>PAYMENT.SALE.COMPLETED</code>'])])
